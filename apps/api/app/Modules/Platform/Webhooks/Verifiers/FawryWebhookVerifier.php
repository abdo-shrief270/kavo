<?php

declare(strict_types=1);

namespace App\Modules\Platform\Webhooks\Verifiers;

/**
 * Fawry settlement callbacks.
 *
 * This is the only path by which a reference payment ever becomes paid: the
 * customer settles at a kiosk with no connection to us at all, so the
 * webhook is not an optimisation, it is the mechanism.
 */
final class FawryWebhookVerifier extends HmacWebhookVerifier
{
    public function __construct()
    {
        parent::__construct(
            secret: (string) config('services.fawry.security_key', ''),
            signatureHeader: 'X-Fawry-Signature',
            timestampHeader: null,
            // Fawry's own transaction reference, unique per settlement, which
            // is what makes a retried callback de-duplicable.
            eventIdPath: 'fawryRefNumber',
            eventTypePath: 'orderStatus',
        );
    }
}
