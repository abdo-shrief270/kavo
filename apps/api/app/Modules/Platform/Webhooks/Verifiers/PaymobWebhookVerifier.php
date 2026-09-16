<?php

declare(strict_types=1);

namespace App\Modules\Platform\Webhooks\Verifiers;

/**
 * Paymob transaction callbacks.
 *
 * Note for the Fawry rail: reference/voucher payments settle hours or days
 * after the order is placed, so settlement arrives here rather than as a
 * response to our own call. That is why the payment interface models an
 * awaiting_offline_payment state rather than only card authorise/capture.
 */
final class PaymobWebhookVerifier extends HmacWebhookVerifier
{
    public function __construct()
    {
        parent::__construct(
            secret: (string) config('services.paymob.webhook_secret'),
            signatureHeader: 'X-Paymob-Signature',
            timestampHeader: null,
            eventIdPath: 'obj.id',
            eventTypePath: 'type',
        );
    }
}
