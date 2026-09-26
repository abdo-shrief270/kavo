<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Ledger\Enums;

enum PayoutStatus: string
{
    /**
     * Created and already taken off the balance.
     *
     * Debited at creation rather than on arrival, so two people cannot pay out
     * the same money while the first transfer is in flight — the same reason
     * stock is reserved at checkout rather than committed at payment.
     */
    case Pending = 'pending';

    case Paid = 'paid';

    /** The transfer bounced. The debit is reversed and the money is owed again. */
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'In flight',
            self::Paid => 'Paid',
            self::Failed => 'Failed',
        };
    }
}
