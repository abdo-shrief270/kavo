<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Ledger\Services;

use App\Modules\Platform\Billing\Ledger\Enums\LedgerEntryType;
use App\Modules\Platform\Billing\Ledger\Enums\PayoutStatus;
use App\Modules\Platform\Billing\Ledger\Models\LedgerEntry;
use App\Modules\Platform\Billing\Ledger\Models\Payout;
use App\Modules\Platform\Billing\Payments\Money;
use App\Modules\Platform\Identity\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Sending a merchant their money.
 *
 * The balance is debited when the payout is *created*, not when it arrives.
 * A transfer takes hours or days to clear, and a balance that still shows the
 * money during that window invites a second payout of the same funds — the
 * same reasoning that reserves stock at checkout instead of committing it at
 * payment. A failed transfer reverses the debit, and the money is owed again.
 */
final readonly class PayoutService
{
    public function __construct(private LedgerService $ledger) {}

    /**
     * @param  array<string, mixed>  $destination
     *
     * @throws ValidationException when the balance will not cover it
     */
    public function request(Tenant $tenant, Money $amount, string $method, array $destination, ?string $notes = null): Payout
    {
        if ($amount->isZero()) {
            throw ValidationException::withMessages(['amount_cents' => 'A payout has to be for something.']);
        }

        if (! in_array($method, Payout::METHODS, true)) {
            throw ValidationException::withMessages(['method' => 'That is not a way this platform pays merchants.']);
        }

        return DB::transaction(function () use ($tenant, $amount, $method, $destination, $notes): Payout {
            /*
             | Lock the tenant row for the rest of this transaction. The check
             | below and the debit after it must be one indivisible step, or
             | two administrators clicking at once each see a balance that
             | covers their payout and the merchant is paid twice. Payouts are
             | rare, so serialising them per tenant costs nothing.
             */
            Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->first();

            $available = $this->ledger->balanceCents($tenant);

            if ($amount->amountCents > $available) {
                throw ValidationException::withMessages([
                    'amount_cents' => sprintf(
                        'That is more than is owed. The balance is %s.',
                        (new Money(max(0, $available), $amount->currency))->format(),
                    ),
                ]);
            }

            $payout = $this->createWithNumber($tenant, [
                'status' => PayoutStatus::Pending,
                'amount_cents' => $amount->amountCents,
                'currency' => $amount->currency,
                'method' => $method,
                'destination' => $destination,
                'notes' => $notes,
                'requested_at' => now(),
            ]);

            $this->post($tenant, LedgerEntryType::Payout, $amount, $payout, 'Payout '.$payout->reference());

            return $payout;
        });
    }

    /** The transfer landed. Nothing moves — the debit was taken at creation. */
    public function markPaid(Payout $payout): Payout
    {
        $this->assertOpen($payout);

        $payout->forceFill(['status' => PayoutStatus::Paid, 'settled_at' => now()])->save();

        return $payout;
    }

    /** It bounced. The debit is reversed and the merchant is owed it again. */
    public function markFailed(Tenant $tenant, Payout $payout, string $reason): Payout
    {
        $this->assertOpen($payout);

        DB::transaction(function () use ($tenant, $payout, $reason): void {
            $payout->forceFill([
                'status' => PayoutStatus::Failed,
                'failure_reason' => $reason,
                'settled_at' => now(),
            ])->save();

            $this->post($tenant, LedgerEntryType::PayoutReversed, $payout->money(), $payout, 'Payout '.$payout->reference().' failed');
        });

        return $payout;
    }

    private function assertOpen(Payout $payout): void
    {
        if ($payout->status->isFinal()) {
            throw ValidationException::withMessages([
                'status' => sprintf('Payout %s is already %s.', $payout->reference(), $payout->status->value),
            ]);
        }
    }

    private function post(Tenant $tenant, LedgerEntryType $type, Money $amount, Payout $payout, string $description): void
    {
        LedgerEntry::create([
            'tenant_id' => $tenant->getKey(),
            'type' => $type,
            'amount_cents' => $amount->amountCents * $type->sign(),
            'currency' => $amount->currency,
            'source_type' => 'payout',
            'source_id' => $payout->getKey(),
            'description' => $description,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Allocate the next payout number for this tenant.
     *
     * max()+1 under the tenant's own row-level security, exactly as order
     * numbers are allocated, so a merchant reconciling their fourth payout
     * sees #4 rather than a platform-wide sequence. The tenant row is already
     * locked by the caller, so a collision here means something else is
     * writing payouts outside this service; the retry is cheap and the unique
     * index is the real guard.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createWithNumber(Tenant $tenant, array $attributes): Payout
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $next = ((int) Payout::query()->where('tenant_id', $tenant->getKey())->max('number') ?: Payout::FIRST_NUMBER) + 1;

            try {
                // A savepoint: this runs inside the caller's transaction, and
                // a unique violation aborts a Postgres transaction outright.
                return DB::transaction(fn (): Payout => Payout::create([
                    ...$attributes,
                    'tenant_id' => $tenant->getKey(),
                    'number' => $next,
                ]));
            } catch (QueryException $e) {
                if ($e->getCode() !== '23505' || $attempt === 5) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('Could not allocate a payout number.');
    }
}
