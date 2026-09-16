<?php

declare(strict_types=1);

namespace App\Modules\Platform\Identity\Actions;

use App\Models\User;
use App\Modules\Platform\Billing\Models\Plan;
use App\Modules\Platform\Billing\Models\Subscription;
use App\Modules\Platform\Identity\Models\Tenant;
use App\Shared\Enums\Product;
use App\Shared\Enums\TenantStatus;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantDatabaseSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Signup: create the tenant, make the signer its owner, start the trial.
 *
 * Everything runs in one transaction on the owner connection. A half-created
 * tenant — a row with no membership, or a subscription with no tenant — is
 * worse than a failed signup, because nobody can reach it to fix it.
 */
final readonly class ProvisionTenant
{
    public function __construct(
        private TenantContext $context,
        private TenantDatabaseSession $session,
    ) {}

    public function handle(User $owner, string $name, Product $product, ?string $planCode = null): Tenant
    {
        return DB::connection(config('kavo.tenancy.owner_connection'))->transaction(function () use ($owner, $name, $product, $planCode): Tenant {
            $plan = $planCode === null
                ? Plan::query()->where('product', $product->value)->where('is_active', true)->orderBy('sort_order')->first()
                : Plan::query()->where('code', $planCode)->first();

            $tenant = Tenant::query()->create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'status' => TenantStatus::Active,
                'product' => $product,
                'plan_id' => $plan?->getKey(),
                'trial_ends_at' => $plan !== null && $plan->trial_days > 0
                    ? now()->addDays($plan->trial_days)
                    : null,
                'settings' => [],
            ]);

            $tenant->users()->attach($owner->getKey(), [
                'role' => 'owner',
                'joined_at' => now(),
            ]);

            $owner->forceFill(['active_tenant_id' => $tenant->getKey()])->save();

            if ($plan !== null) {
                // Written inside the tenant's own context so the row passes
                // the same RLS policy every other write does.
                $this->context->runAs($tenant, function () use ($tenant, $plan): void {
                    $this->session->bind($tenant->getKey());

                    try {
                        Subscription::create([
                            'tenant_id' => $tenant->getKey(),
                            'plan_id' => $plan->getKey(),
                            'status' => $plan->trial_days > 0 ? 'trialing' : 'active',
                            'trial_ends_at' => $tenant->trial_ends_at,
                            'current_period_start' => now(),
                            'current_period_end' => now()->addMonth(),
                        ]);
                    } finally {
                        $this->session->clear();
                    }
                });
            }

            return $tenant;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'tenant';
        $slug = $base;
        $suffix = 1;

        while (Tenant::query()->withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
