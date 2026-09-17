<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Listeners;

use App\Modules\Platform\Billing\Payments\DuplicateSettlement;
use App\Modules\Platform\Billing\Payments\PaymentGatewayManager;
use App\Modules\Platform\Billing\Payments\PaymentService;
use App\Modules\Platform\Identity\Models\Tenant;
use App\Shared\Events\InboundWebhookReceived;
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

    public function handle(InboundWebhookReceived $event): void
    {
        // Every inbound callback arrives here, including ones from providers
        // that have nothing to do with payments.
        if (! array_key_exists($event->provider, (array) config('kavo.payments.gateways', []))) {
            return;
        }

        $gateway = $this->gateways->gateway($event->provider);
        $notice = $gateway->parseSettlement($event->payload);

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
     *
     * Matched on `public_reference`. The merchant's own `reference` is unique
     * only per tenant, so resolving on it here would let one tenant claim
     * another's settlement simply by registering the same order number first:
     * the lookup runs on a BYPASSRLS connection with no tenant bound, so
     * neither isolation layer would catch the mis-resolution, and everything
     * downstream would then run bound to the wrong tenant.
     *
     * The ambiguity guard below should be unreachable — `public_reference`
     * carries a unique index — and is kept because failing closed on a
     * cross-tenant lookup is worth more than the query it costs.
     */
    private function tenantFor(string $publicReference, string $gateway): ?Tenant
    {
        $tenantIds = DB::connection(config('kavo.tenancy.owner_connection'))
            ->table('payment_intents')
            ->where('public_reference', $publicReference)
            ->where('gateway', $gateway)
            ->limit(2)
            ->pluck('tenant_id');

        if ($tenantIds->count() > 1) {
            Log::critical('Ambiguous settlement reference — refusing to guess a tenant', [
                'gateway' => $gateway,
                'public_reference' => $publicReference,
            ]);

            return null;
        }

        $tenantId = $tenantIds->first();

        return $tenantId === null ? null : Tenant::query()->find($tenantId);
    }
}
