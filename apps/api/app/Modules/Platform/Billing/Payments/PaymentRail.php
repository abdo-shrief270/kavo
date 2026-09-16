<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Payments;

/**
 * How money actually reaches us. Gateways declare which rails they support so
 * the platform can offer a customer only the ones that will work.
 */
enum PaymentRail: string
{
    /** Synchronous authorise/capture, possibly with a 3-D Secure step. */
    case Card = 'card';

    /** Mobile wallet, redirect or push prompt. */
    case Wallet = 'wallet';

    /**
     * Pay-later at a kiosk against a reference number. Asynchronous by
     * nature: settlement arrives by webhook, not as a response to our call.
     */
    case Reference = 'reference';

    /** Paid to the courier. Settlement is reported, never gateway-driven. */
    case CashOnDelivery = 'cod';

    public function isOffline(): bool
    {
        return $this === self::Reference || $this === self::CashOnDelivery;
    }

    public function label(): string
    {
        return match ($this) {
            self::Card => 'Card',
            self::Wallet => 'Mobile wallet',
            self::Reference => 'Pay at an outlet',
            self::CashOnDelivery => 'Cash on delivery',
        };
    }
}
