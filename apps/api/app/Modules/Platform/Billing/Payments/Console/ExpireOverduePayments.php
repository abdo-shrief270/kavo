<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Payments\Console;

use App\Modules\Platform\Billing\Payments\PaymentService;
use App\Modules\Platform\Identity\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantDatabaseSession;
use Illuminate\Console\Command;

/**
 * Closes out offline payments whose window has passed.
 *
 * Runs per tenant with that tenant bound, because payment_intents is
 * tenant-scoped and a sweep with no tenant would touch nothing at all. An
 * unpaid Fawry reference left open holds its reservation forever — the
 * catalogue sells out to customers who never paid.
 */
final class ExpireOverduePayments extends Command
{
    protected $signature = 'kavo:expire-payments';

    protected $description = 'Expire payment intents whose offline window has closed';

    public function handle(PaymentService $payments, TenantContext $context, TenantDatabaseSession $session): int
    {
        $total = 0;

        Tenant::query()->chunkById(50, function ($tenants) use ($payments, $context, $session, &$total): void {
            foreach ($tenants as $tenant) {
                $context->runAs($tenant, function () use ($payments, $session, $tenant, &$total): void {
                    $session->runBound($tenant->getKey(), function () use ($payments, &$total): void {
                        $total += $payments->expireOverdue();
                    });
                });
            }
        });

        $this->info("Expired {$total} overdue payment(s).");

        return self::SUCCESS;
    }
}
