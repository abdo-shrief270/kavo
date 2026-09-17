<?php

declare(strict_types=1);

namespace App\Modules\Platform\Webhooks\Jobs;

use App\Modules\Platform\Webhooks\Models\WebhookEvent;
use App\Shared\Events\InboundWebhookReceived;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * The job is idempotent, not the endpoint.
 *
 * Providers retry, and the unique constraint only stops a duplicate *row* —
 * it does not stop this job running twice for the same row. The processed_at
 * claim below is what makes the side effects happen once.
 */
final class ProcessInboundWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $webhookEventId) {}

    public function handle(): void
    {
        // Claim the event with a conditional update. Two workers racing on
        // the same event means exactly one sees a non-zero result, so the
        // handler runs once even under concurrent delivery.
        $claimed = DB::table('webhook_events')
            ->where('id', $this->webhookEventId)
            ->whereNull('processed_at')
            ->update(['processed_at' => now(), 'updated_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $event = WebhookEvent::find($this->webhookEventId);

        if ($event === null) {
            return;
        }

        try {
            // A typed event, not a string assembled from the provider's own
            // vocabulary. The string form meant a listener had to be
            // registered against every event-type a provider might send, and
            // guessing one wrong is silent: the callback is accepted, marked
            // processed, and acted on by nobody.
            Event::dispatch(new InboundWebhookReceived(
                provider: $event->provider,
                eventType: $event->event_type,
                externalEventId: $event->external_event_id,
                payload: $event->payload ?? [],
            ));
        } catch (\Throwable $e) {
            // Release the claim so a retry can pick it up; leaving it claimed
            // would silently drop the event.
            $event->update(['processed_at' => null, 'error' => $e->getMessage()]);

            Log::error('Inbound webhook processing failed', [
                'webhook_event_id' => $event->id,
                'provider' => $event->provider,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
