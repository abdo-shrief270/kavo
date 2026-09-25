<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Orders\Enums;

/**
 * Where an order stands.
 *
 * AwaitingPayment is the state this market makes unavoidable. A large share of
 * Egyptian commerce is paid at a kiosk against a reference, hours or days
 * after the order is placed, so "ordered but not yet paid" is a normal
 * multi-day condition rather than the couple of seconds a card takes. Code
 * that treats anything short of Paid as a failure cancels every one of them.
 */
enum OrderStatus: string
{
    /** Placed. The gateway has not answered yet, or could not be reached. */
    case Pending = 'pending';

    /** A reference was issued or a redirect is outstanding. Stock is held. */
    case AwaitingPayment = 'awaiting_payment';

    case Paid = 'paid';

    /** Cancelled by the merchant, or by the customer before paying. */
    case Cancelled = 'cancelled';

    /** The payment window closed without payment. */
    case Expired = 'expired';

    case Refunded = 'refunded';

    /** Still owed money, and still holding the stock it reserved. */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::AwaitingPayment;
    }

    public function isFinal(): bool
    {
        return ! $this->isOpen();
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::AwaitingPayment => 'Awaiting payment',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
            self::Refunded => 'Refunded',
        };
    }
}
