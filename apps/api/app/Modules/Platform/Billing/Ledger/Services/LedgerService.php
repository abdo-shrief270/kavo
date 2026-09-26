<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Ledger\Services;

use App\Modules\Platform\Billing\Ledger\Enums\LedgerEntryType;
use App\Modules\Platform\Billing\Ledger\Models\LedgerEntry;
use App\Modules\Platform\Billing\Payments\Money;
use App\Modules\Platform\Identity\Models\Tenant;
use App\Shared\Contracts\MerchantLedger;
use App\Shared\Tenancy\TenantDatabaseSession;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The book of what the platform owes each merchant.
 *
 * Two rules hold everywhere in here.
 *
 * Entries are never changed. A balance you can edit is a balance nobody can
 * dispute, and "what did we owe them in March" stops being answerable the
 * moment a row can be corrected in place. A mistake is a new Adjustment entry
 * saying who made it and why.
 *
 * And the balance is derived, never stored. A cached total is a second source
 * of truth that can drift from the entries behind it, and the day it does is
 * the day a merchant is paid the wrong amount. Summing costs a query; a drift
 * costs a reconciliation nobody can win. If the sum ever becomes slow, the fix
 * is a materialised total with a test that it still agrees — not now, and not
 * without numbers to justify it.
 */
final readonly class LedgerService implements MerchantLedger
{
    public function __construct(private TenantDatabaseSession $session) {}

    public function recordSale(Tenant $tenant, Money $gross, string $sourceType, int $sourceId, string $description): void
    {
        $commission = $this->commissionOn($gross);

        // One transaction: a sale credited without its commission would
        // overpay the merchant, and the window between two separate writes is
        // exactly where a crash leaves that.
        DB::transaction(function () use ($tenant, $gross, $commission, $sourceType, $sourceId, $description): void {
            $this->write($tenant, LedgerEntryType::Sale, $gross, $sourceType, $sourceId, $description);

            if (! $commission->isZero()) {
                $this->write(
                    $tenant,
                    LedgerEntryType::Commission,
                    $commission,
                    $sourceType,
                    $sourceId,
                    sprintf('%s commission on %s', $this->ratePercent(), $description),
                );
            }
        });
    }

    public function recordRefund(Tenant $tenant, Money $gross, string $sourceType, int $sourceId, string $description): void
    {
        $commission = $this->commissionOn($gross);

        DB::transaction(function () use ($tenant, $gross, $commission, $sourceType, $sourceId, $description): void {
            $this->write($tenant, LedgerEntryType::Refund, $gross, $sourceType, $sourceId, $description);

            if (! $commission->isZero()) {
                $this->write(
                    $tenant,
                    LedgerEntryType::CommissionReversed,
                    $commission,
                    $sourceType,
                    $sourceId,
                    sprintf('%s commission returned on %s', $this->ratePercent(), $description),
                );
            }
        });
    }

    public function balance(Tenant $tenant): Money
    {
        $this->assertBound($tenant);

        $total = (int) LedgerEntry::query()
            ->where('tenant_id', $tenant->getKey())
            ->sum('amount_cents');

        // A negative balance is real — a refund after a payout leaves the
        // merchant owing the platform — so it is reported rather than floored.
        // Money refuses a negative amount, correctly, so the magnitude and the
        // sign travel separately.
        return new Money(abs($total), $this->currency());
    }

    /** True when the merchant owes the platform rather than the other way round. */
    public function isOverdrawn(Tenant $tenant): bool
    {
        $this->assertBound($tenant);

        return (int) LedgerEntry::query()->where('tenant_id', $tenant->getKey())->sum('amount_cents') < 0;
    }

    /** The signed total, for callers that have to do arithmetic with it. */
    public function balanceCents(Tenant $tenant): int
    {
        $this->assertBound($tenant);

        return (int) LedgerEntry::query()->where('tenant_id', $tenant->getKey())->sum('amount_cents');
    }

    /**
     * Basis points rather than a percentage, and integer arithmetic rather
     * than floating point: 1% of 12,345 piastres is 123.45, and a float that
     * has been through arithmetic is not a sum anyone should be charged.
     *
     * Division truncates, so the fraction of a piastre goes to the merchant.
     * Over a million orders that is a rounding loss of a few pounds for the
     * platform, which is the right direction for it to fall.
     */
    public function commissionOn(Money $gross): Money
    {
        $basisPoints = (int) config('kaabosh.ledger.commission_basis_points', 100);

        return new Money(intdiv($gross->amountCents * $basisPoints, 10_000), $gross->currency);
    }

    /**
     * Write one entry, with the sign its type dictates.
     *
     * Taking the sign from the type rather than from the caller is what stops
     * a commission being credited: an entry with the wrong sign pays the
     * merchant for the privilege of being charged, and nothing downstream
     * would notice.
     */
    private function write(
        Tenant $tenant,
        LedgerEntryType $type,
        Money $amount,
        ?string $sourceType,
        ?int $sourceId,
        string $description,
    ): void {
        try {
            /*
             | A savepoint, because this runs inside the caller's transaction
             | and a unique violation aborts a Postgres transaction outright.
             | Without it, catching the duplicate below would leave the
             | transaction dead and the *next* entry — the commission on the
             | very sale being recorded — would fail with "current transaction
             | is aborted", which is a far worse outcome than the duplicate.
             */
            DB::transaction(fn () => LedgerEntry::create([
                'tenant_id' => $tenant->getKey(),
                'type' => $type,
                'amount_cents' => $amount->amountCents * $type->sign(),
                'currency' => $amount->currency,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'description' => $description,
                'occurred_at' => now(),
            ]));
        } catch (QueryException $e) {
            // 23505: this exact event was already recorded. A provider retried
            // a callback, or a settlement was applied twice. Acknowledged
            // rather than doubled — the normal case, not an error.
            if ($e->getCode() !== '23505') {
                throw $e;
            }

            Log::info('Ledger entry already recorded', [
                'tenant_id' => $tenant->getKey(),
                'type' => $type->value,
                'source' => $sourceType.':'.$sourceId,
            ]);
        }
    }

    /**
     * Refuse to answer with no tenant bound.
     *
     * Row-level security fails closed, which is correct — but "closed" for a
     * SUM is the number zero, and a balance that silently reads zero would be
     * paid out as zero, or worse, treated as a merchant with nothing owing.
     */
    private function assertBound(Tenant $tenant): void
    {
        $bound = $this->session->boundTenantId();

        if ($bound !== $tenant->getKey()) {
            throw new RuntimeException(sprintf(
                'Refusing to read tenant %d\'s balance while the session is bound to %s.',
                $tenant->getKey(),
                $bound === null ? 'nothing' : (string) $bound,
            ));
        }
    }

    private function ratePercent(): string
    {
        return rtrim(rtrim(number_format((int) config('kaabosh.ledger.commission_basis_points', 100) / 100, 2), '0'), '.').'%';
    }

    private function currency(): string
    {
        return (string) config('kaabosh.ledger.currency', 'EGP');
    }
}
