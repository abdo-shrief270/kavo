<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Listeners;

use App\Modules\Platform\Billing\Payments\DuplicateSettlement;
use App\Modules\Platform\Billing\Payments\PaymentGatewayManager;
use App\Modules\Platform\Billing\Payments\PaymentService;
use App\Modules\Platform\Identity\Models\Tenant;
use App\Modules\Platform\Webhooks\Models\WebhookEvent;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantDatabaseSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns a verified provider callback into a payment state change.
 *
 * The tenant is discovered from the intent the settlement refers to, not from
 * ambient context: a webhook arrives with no session and no tenant bound, and
 * the provider has no idea our tenants exist.
 */
final readonly class ApplyGatewaySettlement
{
    public function __construct(
        private PaymentGatewayManager $gateways,
        private PaymentService $payments,
        private TenantContext $context,
        private TenantDatabaseSession $session,
    ) {}

    public function handle(WebhookEvent $event): void
    {
        $gateway = $this->gateways->gateway($event->provider);
        $notice = $gateway->parseSettlement($event->payload ?? []);

        // Not every callback is a settlement — a template status change or a
        // test ping is acknowledged without inventing a payment event.
        if ($notice === null) {
            return;
        }

        $tenant = $this->tenantFor($notice->reference, $event->provider);

        if ($tenant === null) {
            Log::warning('Settlement for a reference no tenant owns', [
                'provider' => $event->provider,
                'reference' => $notice->reference,
            ]);

            return;
        }

        $this->context->runAs($tenant, function () use ($tenant, $notice, $event): void {
            $this->session->runBound($tenant->getKey(), function () use ($notice, $event): void {
                try {
                    $this->payments->settle($notice, $event->provider);
                } catch (DuplicateSettlement) {
                    // The provider retried a callback we already applied.
                    // Acknowledged, not reprocessed — this is the normal case,
                    // not an error worth alerting on.
                    Log::info('Duplicate settlement ignored', [
                        'provider' => $event->provider,
                        'reference' => $notice->reference,
                    ]);
                }
            });
        });
    }

    /**
     * Find the owning tenant.
     *
     * payment_intents is behind RLS, so this reads through the schema owner —
     * the one lookup that legitimately spans tenants, and the mirror of the
     * hostname lookup ResolveTenant does before a tenant is known.
     */
    private function tenantFor(string $reference, string $gateway): ?Tenant
    {
        $tenantId = DB::connection(config('kavo.tenancy.owner_connection'))
            ->table('payment_intents')
            ->where('reference', $reference)
            ->where('gateway', $gateway)
            ->value('tenant_id');

        return $tenantId === null ? null : Tenant::query()->find($tenantId);
    }
}
