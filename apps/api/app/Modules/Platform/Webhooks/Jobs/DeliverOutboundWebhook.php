<?php

declare(strict_types=1);

namespace App\Modules\Platform\Webhooks\Jobs;

use App\Modules\Platform\Identity\Models\Tenant;
use App\Modules\Platform\Webhooks\Models\WebhookDelivery;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantDatabaseSession;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Factory as Http;

/**
 * Delivers one event to one tenant subscription.
 *
 * Failures stay visible in webhook_deliveries with a manual retry in the
 * dashboard. Silently dropping a delivery is what destroys trust in an
 * integration platform, so nothing here disappears into a log.
 */
final class DeliverOutboundWebhook implements ShouldQueue
{
    use Queueable;

    public int $timeout = 20;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $deliveryId,
    ) {}

    public function handle(Http $http, TenantContext $context, TenantDatabaseSession $session): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        // runBound, not bind/clear: on the sync queue — or via dispatchSync —
        // this runs inline inside a request that already has a tenant bound,
        // and clearing it there strands the rest of that request.
        $context->runAs($tenant, function () use ($http, $session, $tenant): void {
            $session->runBound($tenant->getKey(), fn () => $this->deliver($http));
        });
    }

    private function deliver(Http $http): void
    {
        $delivery = WebhookDelivery::with('subscription')->find($this->deliveryId);

        if ($delivery === null || ! $delivery->isRetryable()) {
            return;
        }

        $subscription = $delivery->subscription;

        if ($subscription === null || ! $subscription->is_active) {
            return;
        }

        $attempt = $delivery->attempt + 1;
        $timestamp = (string) now()->timestamp;
        $body = json_encode($delivery->payload, JSON_THROW_ON_ERROR);

        // Timestamp is part of the signed string, so a captured payload
        // cannot be replayed against the tenant's endpoint later.
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $subscription->secret);

        try {
            $response = $http
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Kavo-Event' => $delivery->event_type,
                    'X-Kavo-Delivery' => (string) $delivery->id,
                    'X-Kavo-Timestamp' => $timestamp,
                    'X-Kavo-Signature' => $signature,
                ])
                ->timeout(15)
                ->withBody($body, 'application/json')
                ->post($subscription->url);
        } catch (\Throwable $e) {
            $this->recordFailure($delivery, $attempt, null, $e->getMessage());

            return;
        }

        if ($response->successful()) {
            $delivery->update([
                'status' => 'delivered',
                'attempt' => $attempt,
                'status_code' => $response->status(),
                'response_body' => mb_substr($response->body(), 0, 2000),
                'delivered_at' => now(),
                'next_attempt_at' => null,
            ]);

            $subscription->update(['consecutive_failures' => 0]);

            return;
        }

        $this->recordFailure($delivery, $attempt, $response->status(), mb_substr($response->body(), 0, 2000));
    }

    private function recordFailure(WebhookDelivery $delivery, int $attempt, ?int $status, string $body): void
    {
        $backoff = (array) config('kavo.webhooks.outbound.backoff', [30, 120, 600, 3600, 21600]);

        // Attempts are exhausted: dead-letter rather than retry forever. The
        // row stays in the dashboard so the tenant can fix their endpoint and
        // replay it themselves.
        if ($attempt > count($backoff)) {
            $delivery->update([
                'status' => 'dead_lettered',
                'attempt' => $attempt,
                'status_code' => $status,
                'response_body' => $body,
                'failed_at' => now(),
                'next_attempt_at' => null,
            ]);

            $this->penalise($delivery);

            return;
        }

        $delay = $backoff[$attempt - 1];

        $delivery->update([
            'status' => 'failed',
            'attempt' => $attempt,
            'status_code' => $status,
            'response_body' => $body,
            'failed_at' => now(),
            'next_attempt_at' => now()->addSeconds($delay),
        ]);

        self::dispatch($this->tenantId, $this->deliveryId)->delay(now()->addSeconds($delay));
    }

    /**
     * A subscription whose endpoint has been dead for long enough is switched
     * off and the tenant told, rather than us hammering a dead URL forever.
     */
    private function penalise(WebhookDelivery $delivery): void
    {
        $subscription = $delivery->subscription;

        if ($subscription === null) {
            return;
        }

        $failures = $subscription->consecutive_failures + 1;
        $threshold = (int) config('kavo.webhooks.outbound.disable_after_consecutive_failures', 10);

        $subscription->update([
            'consecutive_failures' => $failures,
            'is_active' => $failures < $threshold,
            'disabled_at' => $failures >= $threshold ? now() : null,
        ]);
    }
}
