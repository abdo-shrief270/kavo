<?php

declare(strict_types=1);

namespace App\Shared\Events;

/**
 * A verified provider callback, ready for whichever module cares about it.
 *
 * Carries the payload rather than the WebhookEvent model on purpose. The
 * Webhooks module owns receiving, verifying and de-duplicating callbacks; what
 * a callback *means* belongs to whoever asked for it. Passing the model made
 * Billing depend on Webhooks' Eloquent layer, which is exactly the coupling
 * modules are supposed to avoid — and it offered a listener far more than it
 * needed, including the ability to write to the receiving module's table.
 */
final readonly class InboundWebhookReceived
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $provider,
        public ?string $eventType,
        /** The provider's own event id — already used as the idempotency key. */
        public ?string $externalEventId,
        public array $payload,
    ) {}
}
