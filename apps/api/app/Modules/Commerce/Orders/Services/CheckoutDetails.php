<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Orders\Services;

use App\Modules\Platform\Billing\Payments\PaymentRail;

/**
 * Everything a checkout needs that is not already in the cart.
 *
 * A value object rather than an array so the one place this is assembled — the
 * controller, from validated input — is the only place a field can be
 * forgotten, and forgetting one is a type error rather than a null landing in
 * a gateway request.
 */
final readonly class CheckoutDetails
{
    public function __construct(
        public string $customerName,
        public string $customerEmail,
        /** Required on every rail: an offline reference has no other way home. */
        public string $customerPhone,
        public PaymentRail $rail,
        /** @var array<string, mixed> */
        public array $shippingAddress = [],
        public int $shippingCents = 0,
        public ?string $returnUrl = null,
    ) {}
}
