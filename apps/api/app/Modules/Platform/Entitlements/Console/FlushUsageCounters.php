<?php

declare(strict_types=1);

namespace App\Modules\Platform\Entitlements\Console;

use App\Modules\Platform\Entitlements\Services\UsageMeter;
use App\Modules\Platform\Identity\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantDatabaseSession;
use Illuminate\Console\Command;

/**
 * Moves buffered Redis counters into Postgres.
 *
 * Runs per tenant with that tenant's context bound: the write goes through
 * RLS like any other, so a job that forgot to bind would write nothing rather
 * than write into the wrong tenant.
 */
final class FlushUsageCounters extends Command
{
    protected $signature = 'kavo:flush-usage';

    protected $description = 'Flush buffered usage counters from Redis into Postgres';

    public function handle(UsageMeter $meter, TenantContext $context, TenantDatabaseSession $session): int
    {
        $dirty = $meter->dirtyCounters();

        if ($dirty === []) {
            $this->info('No buffered counters to flush.');

            return self::SUCCESS;
        }

        $tenants = Tenant::query()
            ->whereIn('id', array_unique(array_column($dirty, 'tenant_id')))
            ->get()
            ->keyBy('id');

        $flushed = 0;

        foreach ($dirty as $entry) {
            $tenant = $tenants->get($entry['tenant_id']);

            if ($tenant === null) {
                // Tenant deleted between increment and flush; drop the marker
                // so it does not retry forever.
                continue;
            }

            $context->runAs($tenant, function () use ($session, $meter, $tenant, $entry, &$flushed): void {
                $session->bind($tenant->getKey());

                try {
                    $meter->flush($tenant, $entry['metric']);
                    $meter->clearDirty($tenant, $entry['metric']);
                    $flushed++;
                } finally {
                    $session->clear();
                }
            });
        }

        $this->info("Flushed {$flushed} counter(s).");

        return self::SUCCESS;
    }
}
