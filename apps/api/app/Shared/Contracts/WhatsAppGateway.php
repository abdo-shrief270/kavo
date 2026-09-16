<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Modules\Platform\Notifications\Results\WhatsAppSendResult;

/**
 * WhatsApp is core to the value proposition, so the provider behind it must
 * be swappable. BeOn fronts the WhatsApp Business API today — which avoids
 * owning Meta app review, template approval and number provisioning per
 * tenant — but going direct to Meta Cloud API, or letting tenants bring their
 * own number, should be a binding change and not a rewrite.
 */
interface WhatsAppGateway
{
    /**
     * Send a pre-approved template message.
     *
     * Business-initiated messages outside the 24-hour customer-service window
     * must use an approved template, so this is the primary send path.
     *
     * @param  array<string, mixed>  $variables
     */
    public function sendTemplate(string $to, string $templateCode, array $variables = [], string $language = 'en'): WhatsAppSendResult;

    /**
     * Send free-form text. Only valid inside the 24-hour window opened by a
     * customer message; outside it the provider will reject the send.
     */
    public function sendText(string $to, string $body): WhatsAppSendResult;

    /** Verify a provider callback against the raw request body. */
    public function verifyWebhookSignature(string $rawBody, string $signature): bool;

    public function name(): string;
}
