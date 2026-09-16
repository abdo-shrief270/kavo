<?php

declare(strict_types=1);

namespace App\Modules\Platform\Entitlements\Services;

use App\Modules\Platform\Entitlements\Models\UsageCounter;
use App\Modules\Platform\Identity\Models\Tenant;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Usage counters live in Redis and flush to Postgres on a schedule.
 *
 * Incrementing a Postgres row on every order would serialise every write for
 * a tenant behind one row lock — the classic multi-tenant write hotspot.
 * Redis absorbs that; Postgres holds the durable total.
 *
 * Metrics listed as accuracy-critical read through to Postgres and add the
 * Redis delta, so a billing-relevant count is never wrong by a flush window.
 */
final class UsageMeter
{
    public function __construct(private readonly RedisFactory $redis) {}

    public function increment(Tenant $tenant, string $metric, int $amount = 1): int
    {
        $key = $this->key($tenant, $metric);

        $value = (int) $this->connection()->incrby($key, $amount);

        // Outlive the period so a flush that runs late still finds the value.
        $this->connection()->expireat($key, Carbon::parse($this->periodStart())->addMonths(2)->timestamp);

        $this->connection()->sadd($this->dirtySetKey(), [$tenant->getKey().':'.$metric]);

        return $this->persistedBase($tenant, $metric) + $value;
    }

    /**
     * Return allowance for a stock metric. Floors at zero: a double release
     * should leave the counter wrong-but-harmless rather than negative, which
     * would silently grant free headroom.
     */
    public function decrement(Tenant $tenant, string $metric, int $amount = 1): int
    {
        $current = $this->current($tenant, $metric);
        $effective = min($amount, $current);

        if ($effective <= 0) {
            return $current;
        }

        $key = $this->key($tenant, $metric);
        $buffered = (int) ($this->connection()->get($key) ?? 0);

        // Take it out of the Redis buffer first, and only reach into the
        // persisted total for whatever the buffer could not cover.
        $fromBuffer = min($effective, $buffered);

        if ($fromBuffer > 0) {
            $this->connection()->decrby($key, $fromBuffer);
        }

        $fromPersisted = $effective - $fromBuffer;

        if ($fromPersisted > 0) {
            DB::table('usage_counters')
                ->where('tenant_id', $tenant->getKey())
                ->where('metric_key', $metric)
                ->where('period_start', $this->periodStart())
                ->update([
                    'value' => DB::raw("GREATEST(0, value - {$fromPersisted})"),
                    'updated_at' => now(),
                ]);
        }

        $this->connection()->sadd($this->dirtySetKey(), [$tenant->getKey().':'.$metric]);

        return $current - $effective;
    }

    public function current(Tenant $tenant, string $metric): int
    {
        $buffered = (int) ($this->connection()->get($this->key($tenant, $metric)) ?? 0);

        return $this->persistedBase($tenant, $metric) + $buffered;
    }

    /**
     * Move buffered counts into Postgres and reset the buffer.
     *
     * DECRBY by the exact amount read — rather than DEL — so an increment
     * arriving mid-flush is preserved instead of silently dropped.
     */
    public function flush(Tenant $tenant, string $metric): void
    {
        $key = $this->key($tenant, $metric);
        $buffered = (int) ($this->connection()->get($key) ?? 0);

        if ($buffered === 0) {
            return;
        }

        DB::table('usage_counters')->upsert(
            [[
                'tenant_id' => $tenant->getKey(),
                'metric_key' => $metric,
                'period_start' => $this->periodStart(),
                'value' => $buffered,
                'flushed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['tenant_id', 'metric_key', 'period_start'],
            ['value' => DB::raw('usage_counters.value + excluded.value'), 'flushed_at' => now(), 'updated_at' => now()]
        );

        $this->connection()->decrby($key, $buffered);
    }

    /** @return array<int, array{tenant_id: int, metric: string}> */
    public function dirtyCounters(): array
    {
        $members = (array) $this->connection()->smembers($this->dirtySetKey());

        return array_map(static function (string $member): array {
            [$tenantId, $metric] = explode(':', $member, 2);

            return ['tenant_id' => (int) $tenantId, 'metric' => $metric];
        }, $members);
    }

    public function clearDirty(Tenant $tenant, string $metric): void
    {
        $this->connection()->srem($this->dirtySetKey(), [$tenant->getKey().':'.$metric]);
    }

    public function periodStart(): string
    {
        return now()->startOfMonth()->toDateString();
    }

    private function persistedBase(Tenant $tenant, string $metric): int
    {
        return (int) UsageCounter::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('metric_key', $metric)
            ->where('period_start', $this->periodStart())
            ->value('value');
    }

    private function key(Tenant $tenant, string $metric): string
    {
        return sprintf('usage:%d:%s:%s', $tenant->getKey(), $metric, $this->periodStart());
    }

    private function dirtySetKey(): string
    {
        return 'usage:dirty:'.$this->periodStart();
    }

    private function connection(): Connection
    {
        return $this->redis->connection();
    }
}
