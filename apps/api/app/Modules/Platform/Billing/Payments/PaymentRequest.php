<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Payments;

/**
 * What the platform asks a gateway to collect.
 *
 * Customer contact details are required rather than optional: the reference
 * rail has no other way to reach the payer with their code, and a gateway
 * discovering that at send time fails the checkout instead of the validation.
 */
final readonly class PaymentRequest
{
    public function __construct(
        public string $reference,
        public Money $amount,
        public PaymentRail $rail,
        public string $customerName,
        public string $customerEmail,
        public string $customerPhone,
        /** @var array<string, mixed> */
        public array $metadata = [],
        public ?string $returnUrl = null,
    ) {}
}
