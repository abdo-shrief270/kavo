<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Ledger\Http\Controllers\Admin;

use App\Modules\Platform\Audit\Services\AuditRecorder;
use App\Modules\Platform\Billing\Ledger\Models\LedgerEntry;
use App\Modules\Platform\Billing\Ledger\Models\Payout;
use App\Modules\Platform\Billing\Ledger\Services\LedgerService;
use App\Modules\Platform\Billing\Ledger\Services\PayoutService;
use App\Modules\Platform\Billing\Payments\Money;
use App\Modules\Platform\Identity\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantDatabaseSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Paying merchants, from the platform console.
 *
 * Every write here runs *bound to the tenant it concerns*, through runAs and
 * runBound, rather than in the bare platform scope the rest of this console
 * uses. Platform scope only removes the Eloquent global scope; row-level
 * security is a Postgres policy and still matches nothing with no tenant
 * bound, so a payout written from platform scope would be refused by the
 * database — which is the design working, not a limitation to route around.
 *
 * The one query that genuinely spans tenants — "who am I holding money for" —
 * reads through the schema owner and is audited, the same shape as the
 * settlement lookup in ApplyGatewaySettlement.
 */
final class PayoutAdminController
{
    /** Every merchant the platform is holding money for. */
    public function balances(AuditRecorder $audit): JsonResponse
    {
        $audit->record('platform.balances_viewed');

        $rows = DB::connection(config('kavo.tenancy.owner_connection'))
            ->table('ledger_entries')
            ->join('tenants', 'tenants.id', '=', 'ledger_entries.tenant_id')
            ->groupBy('tenants.id', 'tenants.name', 'tenants.slug')
            ->orderByRaw('sum(ledger_entries.amount_cents) desc')
            ->get([
                'tenants.id as tenant_id',
                'tenants.name',
                'tenants.slug',
                DB::raw('sum(ledger_entries.amount_cents) as balance_cents'),
            ]);

        return response()->json([
            'balances' => $rows->map(fn ($row): array => [
                'tenant_id' => (int) $row->tenant_id,
                'name' => $row->name,
                'slug' => $row->slug,
                'balance_cents' => (int) $row->balance_cents,
            ])->all(),
            'currency' => (string) config('kavo.ledger.currency', 'EGP'),
        ]);
    }

    public function show(Tenant $tenant, LedgerService $ledger, TenantContext $context, TenantDatabaseSession $session): JsonResponse
    {
        return response()->json($context->runAs($tenant, fn (): array => $session->runBound($tenant->getKey(), fn (): array => [
            'tenant' => ['id' => $tenant->getKey(), 'name' => $tenant->name, 'slug' => $tenant->slug],
            'balance_cents' => $ledger->balanceCents($tenant),
            'currency' => (string) config('kavo.ledger.currency', 'EGP'),
            'entries' => LedgerEntry::query()
                ->latest('occurred_at')
                ->latest('id')
                ->limit(100)
                ->get()
                ->map(fn (LedgerEntry $entry): array => $entry->summary())
                ->all(),
            'payouts' => Payout::query()
                ->latest('number')
                ->limit(25)
                ->get()
                ->map(fn (Payout $payout): array => $payout->summary())
                ->all(),
        ])));
    }

    public function store(
        Request $request,
        Tenant $tenant,
        PayoutService $payouts,
        AuditRecorder $audit,
        TenantContext $context,
        TenantDatabaseSession $session,
    ): JsonResponse {
        $validated = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::in(Payout::METHODS)],
            'destination' => ['required', 'array'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $payout = $context->runAs($tenant, fn (): Payout => $session->runBound($tenant->getKey(), fn (): Payout => $payouts->request(
            $tenant,
            new Money((int) $validated['amount_cents'], (string) config('kavo.ledger.currency', 'EGP')),
            $validated['method'],
            $validated['destination'],
            $validated['notes'] ?? null,
        )));

        // Recorded from platform scope, so it lands on the platform trail
        // rather than in the merchant's own audit log — this was the
        // platform's action, not theirs.
        $audit->record('payout.created', null, null, [
            'tenant_id' => $tenant->getKey(),
            'payout' => $payout->reference(),
            'amount_cents' => $payout->amount_cents,
            'method' => $payout->method,
        ]);

        return response()->json(['payout' => $payout->summary()], 201);
    }

    /** The transfer landed, or it bounced. */
    public function settle(
        Request $request,
        Tenant $tenant,
        string $payout,
        PayoutService $payouts,
        AuditRecorder $audit,
        TenantContext $context,
        TenantDatabaseSession $session,
    ): JsonResponse {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['paid', 'failed'])],
            'reason' => ['required_if:status,failed', 'nullable', 'string', 'max:500'],
        ]);

        $settled = $context->runAs($tenant, fn (): Payout => $session->runBound($tenant->getKey(), function () use ($tenant, $payout, $payouts, $validated): Payout {
            // Loaded here rather than by route-model binding: binding runs
            // before this block, in platform scope, where row-level security
            // correctly matches no tenant rows at all.
            $record = Payout::query()->whereKey($payout)->first()
                ?? throw new NotFoundHttpException('No such payout.');

            return $validated['status'] === 'paid'
                ? $payouts->markPaid($record)
                : $payouts->markFailed($tenant, $record, (string) $validated['reason']);
        }));

        $audit->record('payout.'.$validated['status'], null, null, [
            'tenant_id' => $tenant->getKey(),
            'payout' => $settled->reference(),
            'reason' => $validated['reason'] ?? null,
        ]);

        return response()->json(['payout' => $settled->summary()]);
    }
}
