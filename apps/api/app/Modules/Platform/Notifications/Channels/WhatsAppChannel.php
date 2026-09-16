<?php

declare(strict_types=1);

namespace App\Modules\Platform\Notifications\Channels;

use App\Modules\Platform\Notifications\Jobs\SendWhatsAppNotification;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Notifications\Notification;

/**
 * Laravel notification channel for WhatsApp.
 *
 * Every send is queued rather than performed inline: a provider round trip
 * inside a web request couples response time to a third party.
 */
final class WhatsAppChannel
{
    public function __construct(private readonly TenantContext $context) {}

    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toWhatsApp')) {
            return;
        }

        $message = $notification->toWhatsApp($notifiable);
        $recipient = $message['to'] ?? $notifiable->routeNotificationFor('whatsapp');

        if (empty($recipient)) {
            return;
        }

        $tenantId = $message['tenant_id'] ?? $this->context->id();

        if ($tenantId === null) {
            return;
        }

        SendWhatsAppNotification::dispatch(
            (int) $tenantId,
            (string) $recipient,
            (string) $message['template'],
            (array) ($message['variables'] ?? []),
            (string) ($message['event_type'] ?? 'generic'),
            (string) ($message['language'] ?? 'en'),
        );
    }
}
