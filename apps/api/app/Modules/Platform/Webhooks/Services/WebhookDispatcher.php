<?php

declare(strict_types=1);

namespace App\Modules\Platform\Webhooks\Services;

use App\Modules\Platform\Identity\Models\Tenant;
use App\Modules\Platform\Webhooks\Jobs\DeliverOutboundWebhook;
use App\Modules\Platform\Webhooks\Models\WebhookDelivery;
use App\Modules\Platform\Webhooks\Models\WebhookSubscription;
use App\Shared\Contracts\OutboundEvents;

/**
 * Fans one platform event out to every subscription that asked for it.
 *
 * Reached through the OutboundEvents contract rather than by name, so a
 * vertical raising `order.paid` depends on there being somewhere to say it
 * and not on this class, its queue, or its retry schedule.
 */
final class WebhookDispatcher implements OutboundEvents
{
    /** @param array<string, mixed> $payload */
    public function publish(Tenant $tenant, string $eventType, array $payload): int
    {
        $subscriptions = WebhookSubscription::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('is_active', true)
            ->get()
            ->filter(fn (WebhookSubscription $s): bool => $s->subscribesTo($eventType));

        foreach ($subscriptions as $subscription) {
            $delivery = WebhookDelivery::create([
                'tenant_id' => $tenant->getKey(),
                'webhook_subscription_id' => $subscription->getKey(),
                'event_type' => $eventType,
                'payload' => $payload,
                'status' => 'pending',
                'attempt' => 0,
            ]);

            DeliverOutboundWebhook::dispatch($tenant->getKey(), $delivery->getKey());
        }

        return $subscriptions->count();
    }

    /** Manual retry from the merchant dashboard. */
    public function retry(WebhookDelivery $delivery): void
    {
        $delivery->update(['status' => 'pending', 'next_attempt_at' => null]);

        DeliverOutboundWebhook::dispatch($delivery->tenant_id, $delivery->getKey());
    }
}
