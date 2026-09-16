<?php

declare(strict_types=1);

namespace App\Modules\Platform\Notifications\Gateways;

use App\Modules\Platform\Notifications\Results\WhatsAppSendResult;
use App\Shared\Contracts\WhatsAppGateway;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Local and test implementation. Keeps the whole notification path exercised
 * — template lookup, approval check, delivery logging — without a provider
 * account or a live number.
 */
final class LogWhatsAppGateway implements WhatsAppGateway
{
    public function name(): string
    {
        return 'log';
    }

    public function sendTemplate(string $to, string $templateCode, array $variables = [], string $language = 'en'): WhatsAppSendResult
    {
        Log::info('WhatsApp template send', compact('to', 'templateCode', 'variables', 'language'));

        return WhatsAppSendResult::accepted('log-'.Str::uuid()->toString());
    }

    public function sendText(string $to, string $body): WhatsAppSendResult
    {
        Log::info('WhatsApp text send', compact('to', 'body'));

        return WhatsAppSendResult::accepted('log-'.Str::uuid()->toString());
    }

    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        return true;
    }
}
