<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Ledger\Enums;

/**
 * Why an entry exists.
 *
 * Each one has a fixed sign, stated here rather than left to whoever writes
 * the entry: a commission that is accidentally positive pays the merchant
 * for the privilege of being charged, and nothing downstream would notice.
 */
enum LedgerEntryType: string
{
    /** An order settled. The gross the customer paid. */
    case Sale = 'sale';

    /** The platform's cut of that sale. */
    case Commission = 'commission';

    /** Money returned to a customer, taken back off what the merchant is owed. */
    case Refund = 'refund';

    /** The commission on a sale that has since been refunded. */
    case CommissionReversed = 'commission_reversed';

    /** Money sent to the merchant. */
    case Payout = 'payout';

    /** A payout that did not arrive. */
    case PayoutReversed = 'payout_reversed';

    /** A human correcting something. Either sign; always has a description. */
    case Adjustment = 'adjustment';

    /** +1 credits the merchant, -1 takes it back, 0 means the caller decides. */
    public function sign(): int
    {
        return match ($this) {
            self::Sale, self::CommissionReversed, self::PayoutReversed => 1,
            self::Commission, self::Refund, self::Payout => -1,
            self::Adjustment => 0,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Sale',
            self::Commission => 'Platform commission',
            self::Refund => 'Refund',
            self::CommissionReversed => 'Commission returned',
            self::Payout => 'Payout',
            self::PayoutReversed => 'Payout reversed',
            self::Adjustment => 'Adjustment',
        };
    }
}
