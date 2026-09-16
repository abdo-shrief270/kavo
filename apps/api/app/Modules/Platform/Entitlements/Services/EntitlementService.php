<?php

declare(strict_types=1);

namespace App\Modules\Platform\Entitlements\Services;

use App\Modules\Platform\Billing\Models\PlanFeature;
use App\Modules\Platform\Billing\Models\Subscription;
use App\Modules\Platform\Entitlements\Results\ConsumeResult;
use App\Modules\Platform\Identity\Models\Tenant;
use App\Shared\Contracts\Entitlements;
use App\Shared\Events\QuotaThresholdReached;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Event;

final class EntitlementService implements Entitlements
{
    /** Plan shape changes rarely; a request should never pay a query for it. */
    private const CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly Cache $cache,
        private readonly UsageMeter $meter,
    ) {}

    public function can(Tenant $tenant, string $feature): bool
    {
        $features = $this->features($tenant);

        if (! array_key_exists($feature, $features)) {
            return false;
        }

        // A limit of 0 is an explicit denial; null means unlimited.
        return $features[$feature]['limit'] === null || $features[$feature]['limit'] > 0;
    }

    public function consume(Tenant $tenant, string $metric, int $amount = 1): ConsumeResult
    {
        $feature = $this->features($tenant)[$metric] ?? null;

        // An unmetered metric is not an implicit allowance. Refusing here
        // means a typo in a metric key surfaces as a blocked action rather
        // than as unlimited free usage.
        if ($feature === null) {
            return ConsumeResult::overage($metric, 0, 0, 'block');
        }

        $limit = $feature['limit'];
        $behavior = $feature['overage_behavior'];

        if ($limit === null) {
            return ConsumeResult::allowed($metric, $this->meter->increment($tenant, $metric, $amount), null);
        }

        // Check before incrementing when the plan blocks, so a refused action
        // does not inflate the counter it was refused by.
        if ($behavior === 'block' && $this->meter->current($tenant, $metric) + $amount > $limit) {
            return ConsumeResult::overage($metric, $this->meter->current($tenant, $metric), $limit, $behavior);
        }

        $used = $this->meter->increment($tenant, $metric, $amount);

        $this->announceThresholds($tenant, $metric, $used, $limit);

        return $used > $limit
            ? ConsumeResult::overage($metric, $used, $limit, $behavior)
            : ConsumeResult::allowed($metric, $used, $limit, $behavior);
    }

    public function release(Tenant $tenant, string $metric, int $amount = 1): void
    {
        $this->meter->decrement($tenant, $metric, $amount);
    }

    public function remaining(Tenant $tenant, string $metric): ?int
    {
        $feature = $this->features($tenant)[$metric] ?? null;

        // Distinguished deliberately: `?? 0` on the limit would collapse
        // "unlimited" (a null limit) into "nothing left", which is the exact
        // opposite of what the plan grants.
        if ($feature === null) {
            return 0;
        }

        if ($feature['limit'] === null) {
            return null;
        }

        return max(0, $feature['limit'] - $this->meter->current($tenant, $metric));
    }

    public function used(Tenant $tenant, string $metric): int
    {
        return $this->meter->current($tenant, $metric);
    }

    public function flush(Tenant $tenant): void
    {
        $this->cache->forget($this->cacheKey($tenant));
    }

    /**
     * Fires once per threshold per period. Redis SETNX is what makes it once:
     * without it a tenant sitting at 80% would be alerted on every request.
     */
    private function announceThresholds(Tenant $tenant, string $metric, int $used, int $limit): void
    {
        if ($limit <= 0) {
            return;
        }

        $percent = ($used / $limit) * 100;

        foreach ((array) config('kavo.quotas.alert_thresholds', [80, 100]) as $threshold) {
            if ($percent < $threshold) {
                continue;
            }

            $marker = sprintf('quota-alert:%d:%s:%s:%d', $tenant->getKey(), $metric, $this->meter->periodStart(), $threshold);

            if ($this->cache->add($marker, true, now()->addDays(40))) {
                Event::dispatch(new QuotaThresholdReached($tenant, $metric, (int) $threshold, $used, $limit));
            }
        }
    }

    /**
     * @return array<string, array{limit: int|null, overage_behavior: string}>
     */
    private function features(Tenant $tenant): array
    {
        return $this->cache->remember(
            $this->cacheKey($tenant),
            self::CACHE_TTL_SECONDS,
            function () use ($tenant): array {
                $subscription = Subscription::query()
                    ->where('tenant_id', $tenant->getKey())
                    ->whereIn('status', ['active', 'trialing'])
                    ->latest('id')
                    ->first();

                $planId = $subscription?->plan_id ?? $tenant->plan_id;

                if ($planId === null) {
                    return [];
                }

                return PlanFeature::query()
                    ->where('plan_id', $planId)
                    ->get()
                    ->mapWithKeys(fn (PlanFeature $feature): array => [
                        $feature->feature_key => [
                            'limit' => $feature->limit_value,
                            'overage_behavior' => $feature->overage_behavior,
                        ],
                    ])
                    ->all();
            }
        );
    }

    private function cacheKey(Tenant $tenant): string
    {
        return 'entitlements:tenant:'.$tenant->getKey();
    }
}
