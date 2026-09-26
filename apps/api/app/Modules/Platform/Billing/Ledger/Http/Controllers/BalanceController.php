<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Ledger\Http\Controllers;

use App\Modules\Platform\Billing\Ledger\Enums\PayoutStatus;
use App\Modules\Platform\Billing\Ledger\Models\LedgerEntry;
use App\Modules\Platform\Billing\Ledger\Models\Payout;
use App\Modules\Platform\Billing\Ledger\Services\LedgerService;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the merchant is owed, and what has been sent.
 *
 * Read-only on purpose. A merchant does not create their own payout: the money
 * is in the platform's gateway account and leaving it is the platform's action,
 * not a button on a dashboard.
 */
final class BalanceController
{
    public function show(LedgerService $ledger, TenantContext $tenants): JsonResponse
    {
        $tenant = $tenants->getOrFail('reading the balance');
        $cents = $ledger->balanceCents($tenant);

        return response()->json([
            'balance' => [
                // Signed, because a refund after a payout genuinely leaves the
                // merchant owing the platform, and rounding that up to zero
                // would hide it.
                'amount_cents' => $cents,
                'currency' => (string) config('kavo.ledger.currency', 'EGP'),
                'overdrawn' => $cents < 0,
            ],
            'commission_basis_points' => (int) config('kavo.ledger.commission_basis_points', 100),
            'in_flight_cents' => (int) Payout::query()
                ->where('status', PayoutStatus::Pending->value)
                ->sum('amount_cents'),
            'entries' => LedgerEntry::query()
                ->latest('occurred_at')
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (LedgerEntry $entry): array => $entry->summary())
                ->all(),
        ]);
    }

    public function payouts(Request $request): JsonResponse
    {
        $payouts = Payout::query()
            ->when(
                $request->string('status')->toString(),
                fn ($query, string $status) => $query->where('status', $status),
            )
            ->latest('number')
            ->paginate(25);

        $payouts->getCollection()->transform(fn (Payout $payout): array => $payout->summary());

        return response()->json($payouts);
    }
}
