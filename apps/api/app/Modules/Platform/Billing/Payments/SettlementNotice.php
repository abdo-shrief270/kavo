<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Payments;

use App\Shared\Enums\PaymentStatus;

/**
 * A gateway telling us an intent's outcome changed.
 *
 * This is the only way a payment ever becomes `succeeded` on an offline rail,
 * and the safest way on a card rail too: the customer's browser can be closed
 * mid-redirect, but the webhook still arrives.
 */
final readonly class SettlementNotice
{
    public function __construct(
        /** Our reference, echoed back by the provider. */
        public string $reference,
        public PaymentStatus $status,
        public ?string $gatewayReference = null,
        public ?int $amountCents = null,
        /** The provider's own event id — the idempotency key. */
        public ?string $externalEventId = null,
        public ?string $error = null,
        /** @var array<string, mixed> */
        public array $raw = [],
    ) {}
}
