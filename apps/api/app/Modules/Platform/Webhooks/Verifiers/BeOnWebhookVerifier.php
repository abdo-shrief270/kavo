<?php

declare(strict_types=1);

namespace App\Modules\Platform\Webhooks\Verifiers;

/**
 * BeOn relays Meta Cloud API callbacks: delivery status, inbound customer
 * messages, and template approval changes.
 */
final class BeOnWebhookVerifier extends HmacWebhookVerifier
{
    public function __construct()
    {
        parent::__construct(
            secret: (string) config('services.beon.webhook_secret'),
            signatureHeader: 'X-Beon-Signature',
            timestampHeader: 'X-Beon-Timestamp',
            eventIdPath: 'event_id',
            eventTypePath: 'event',
        );
    }
}
