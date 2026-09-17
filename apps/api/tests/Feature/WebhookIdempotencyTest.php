<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Platform\Webhooks\Jobs\ProcessInboundWebhook;
use App\Modules\Platform\Webhooks\Models\WebhookEvent;
use App\Shared\Events\InboundWebhookReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Idempotency is mandatory here, not a nicety: BeOn and Meta both retry, and
 * double-processing an inbound order message means a duplicate order.
 */
final class WebhookIdempotencyTest extends TestCase
{
    private const SECRET = 'test-beon-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.beon.webhook_secret', self::SECRET);
    }

    /** @param array<string, mixed> $payload */
    private function postSigned(array $payload, ?string $signature = null, ?string $timestamp = null): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp ??= (string) time();

        return $this->call(
            'POST',
            '/webhooks/beon',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_BEON_SIGNATURE' => $signature ?? hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET),
                'HTTP_X_BEON_TIMESTAMP' => $timestamp,
            ],
            content: $body,
        );
    }

    #[Test]
    public function it_accepts_and_stores_a_correctly_signed_event(): void
    {
        Queue::fake();

        $this->postSigned(['event_id' => 'evt_1', 'event' => 'message.delivered'])->assertStatus(202);

        $this->assertDatabaseHas('webhook_events', [
            'provider' => 'beon',
            'external_event_id' => 'evt_1',
            'event_type' => 'message.delivered',
            'signature_valid' => true,
        ]);

        Queue::assertPushed(ProcessInboundWebhook::class);
    }

    #[Test]
    public function it_rejects_an_invalid_signature(): void
    {
        $this->postSigned(['event_id' => 'evt_2', 'event' => 'message.read'], signature: 'nonsense')
            ->assertStatus(401);

        $this->assertDatabaseMissing('webhook_events', ['external_event_id' => 'evt_2']);
    }

    /**
     * Without a freshness window a captured signature stays valid forever and
     * the request can be replayed indefinitely.
     */
    #[Test]
    public function it_rejects_a_stale_timestamp(): void
    {
        $stale = (string) (time() - 3600);

        $this->postSigned(['event_id' => 'evt_3', 'event' => 'message.read'], timestamp: $stale)
            ->assertStatus(401);
    }

    #[Test]
    public function it_rejects_an_event_with_no_id_because_it_cannot_be_deduplicated(): void
    {
        $this->postSigned(['event' => 'message.read'])->assertStatus(422);
    }

    #[Test]
    public function a_replayed_event_is_acknowledged_but_stored_once(): void
    {
        Queue::fake();

        $payload = ['event_id' => 'evt_dup', 'event' => 'message.delivered'];

        $this->postSigned($payload)->assertStatus(202);
        $this->postSigned($payload)->assertStatus(200);
        $this->postSigned($payload)->assertStatus(200);

        $this->assertSame(1, WebhookEvent::query()->where('external_event_id', 'evt_dup')->count());

        // Only the first delivery queues work. Acknowledging the retries is
        // what stops the provider's retry loop.
        Queue::assertPushed(ProcessInboundWebhook::class, 1);
    }

    #[Test]
    public function the_job_performs_side_effects_exactly_once(): void
    {
        Event::fake();

        $event = WebhookEvent::create([
            'provider' => 'beon',
            'external_event_id' => 'evt_once',
            'event_type' => 'message.delivered',
            'payload' => ['event_id' => 'evt_once'],
            'signature_valid' => true,
            'received_at' => now(),
        ]);

        // Running the same job twice is exactly what happens when a queue
        // redelivers after a worker dies mid-job.
        (new ProcessInboundWebhook($event->id))->handle();
        (new ProcessInboundWebhook($event->id))->handle();

        Event::assertDispatchedTimes(InboundWebhookReceived::class, 1);

        // And it carries what a listener needs without handing over the
        // receiving module's model.
        Event::assertDispatched(InboundWebhookReceived::class, function (InboundWebhookReceived $received): bool {
            return $received->provider === 'beon'
                && $received->eventType === 'message.delivered'
                && $received->externalEventId === 'evt_once'
                && $received->payload === ['event_id' => 'evt_once'];
        });

        $this->assertNotNull($event->fresh()->processed_at);
    }

    #[Test]
    public function an_unknown_provider_is_refused_rather_than_accepted_unverified(): void
    {
        $this->call(
            'POST',
            '/webhooks/madeup',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['event_id' => 'x'], JSON_THROW_ON_ERROR),
        )->assertStatus(404);
    }
}
