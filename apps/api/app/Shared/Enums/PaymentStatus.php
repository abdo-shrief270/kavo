<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * Payment intent states.
 *
 * The important one is AwaitingOfflinePayment. Card rails settle in seconds
 * and their whole lifecycle fits inside a request; Egypt's largest rail does
 * not. Fawry issues a reference the customer pays at a kiosk hours or days
 * later, so "placed but unpaid" has to be a first-class state rather than a
 * transient one — and that is why it is modelled now rather than bolted on
 * after card flows (ADR 0001 §6).
 */
enum PaymentStatus: string
{
    /** Created, nothing attempted yet. */
    case Pending = 'pending';

    /** Customer must do something: 3-D Secure, a redirect, a wallet prompt. */
    case RequiresAction = 'requires_action';

    /**
     * A reference or voucher has been issued and the customer will pay it
     * offline. May sit here for days. Inventory should be reserved, not
     * committed, while it does.
     */
    case AwaitingOfflinePayment = 'awaiting_offline_payment';

    /** Handed to the gateway; waiting for a settlement callback. */
    case Processing = 'processing';

    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /** The offline window closed without payment. */
    case Expired = 'expired';

    case Refunded = 'refunded';

    /** Nothing more will happen without a new intent. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Expired, self::Refunded], true);
    }

    /**
     * Whether the customer still owes us money on this intent. An offline
     * reference is open in a way a failed card is not.
     */
    public function isOpen(): bool
    {
        return in_array(
            $this,
            [self::Pending, self::RequiresAction, self::AwaitingOfflinePayment, self::Processing],
            true,
        );
    }

    /**
     * Whether the order behind it should hold stock rather than consume it.
     * Committing inventory against a reference nobody ever pays is how a
     * catalogue sells out to no one.
     */
    public function reservesRatherThanCommits(): bool
    {
        return $this === self::AwaitingOfflinePayment || $this === self::RequiresAction;
    }
}
