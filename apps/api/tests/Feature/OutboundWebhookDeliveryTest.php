<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Platform\Identity\Models\Tenant;
use App\Modules\Platform\Webhooks\Jobs\DeliverOutboundWebhook;
use App\Modules\Platform\Webhooks\Models\WebhookDelivery;
use App\Modules\Platform\Webhooks\Models\WebhookSubscription;
use App\Modules\Platform\Webhooks\Services\WebhookDispatcher;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantDatabaseSession;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Outbound delivery against endpoints that misbehave.
 *
 * This path was written and never exercised. Everything it does only matters
 * when the tenant's endpoint is broken — which is exactly when nobody is
 * watching, and exactly when silently dropping an event destroys trust in the
 * integration.
 */
final class OutboundWebhookDeliveryTest extends TestCase
{
    private Tenant $tenant;

    private WebhookSubscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->actingAsTenant($this->tenant);

        $this->subscription = WebhookSubscription::factory()->create([
            'url' => 'https://merchant.example.test/hooks',
            'secret' => 'shared-secret',
            'event_types' => ['order.placed'],
        ]);
    }

    private function deliver(): WebhookDelivery
    {
        app(WebhookDispatcher::class)->dispatch($this->tenant, 'order.placed', ['id' => 'ord_1']);

        return WebhookDelivery::query()->latest('id')->firstOrFail();
    }

    #[Test]
    public function a_successful_delivery_is_signed_and_recorded(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $delivery = $this->deliver();
        (new DeliverOutboundWebhook($this->tenant->getKey(), $delivery->getKey()))->handle(
            app(Factory::class),
            app(TenantContext::class),
            app(TenantDatabaseSession::class),
        );

        $this->assertSame('delivered', $delivery->refresh()->status);
        $this->assertSame(200, $delivery->status_code);
        $this->assertSame(1, $delivery->attempt);

        Http::assertSent(function ($request): bool {
            $timestamp = $request->header('X-Kavo-Timestamp')[0] ?? '';
            $signature = $request->header('X-Kavo-Signature')[0] ?? '';

            // The timestamp is part of the signed string, so a payload
            // captured once cannot be replayed against the tenant later.
            $expected = hash_hmac('sha256', $timestamp.'.'.$request->body(), 'shared-secret');

            return hash_equals($expected, $signature)
                && ($request->header('X-Kavo-Event')[0] ?? '') === 'order.placed';
        });
    }

    #[Test]
    public function a_failing_endpoint_is_retried_with_backoff(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);
        Queue::fake();

        $delivery = $this->deliver();
        (new DeliverOutboundWebhook($this->tenant->getKey(), $delivery->getKey()))->handle(
            app(Factory::class),
            app(TenantContext::class),
            app(TenantDatabaseSession::class),
        );

        $delivery->refresh();

        $this->assertSame('failed', $delivery->status);
        $this->assertSame(500, $delivery->status_code);
        // Scheduled, not abandoned — and not hammered either.
        $this->assertNotNull($delivery->next_attempt_at);
        $this->assertTrue($delivery->next_attempt_at->isFuture());

        Queue::assertPushed(DeliverOutboundWebhook::class);
    }

    #[Test]
    public function a_connection_error_is_treated_as_a_failure_not_a_crash(): void
    {
        // The tenant's DNS is wrong, or their host is unreachable. That is
        // their problem to fix, not an exception that loses the delivery.
        Http::fake(fn () => throw new ConnectionException('unreachable'));
        Queue::fake();

        $delivery = $this->deliver();
        (new DeliverOutboundWebhook($this->tenant->getKey(), $delivery->getKey()))->handle(
            app(Factory::class),
            app(TenantContext::class),
            app(TenantDatabaseSession::class),
        );

        $delivery->refresh();

        $this->assertSame('failed', $delivery->status);
        $this->assertNull($delivery->status_code);
        $this->assertStringContainsString('unreachable', (string) $delivery->response_body);
    }

    #[Test]
    public function attempts_are_exhausted_into_a_dead_letter_rather_than_retried_forever(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);
        Queue::fake();

        $backoff = (array) config('kavo.webhooks.outbound.backoff');
        $delivery = $this->deliver();

        // One more attempt than the backoff schedule allows.
        for ($i = 0; $i <= count($backoff); $i++) {
            $delivery->update(['status' => 'pending']);
            (new DeliverOutboundWebhook($this->tenant->getKey(), $delivery->getKey()))->handle(
                app(Factory::class),
                app(TenantContext::class),
                app(TenantDatabaseSession::class),
            );
        }

        $delivery->refresh();

        $this->assertSame('dead_lettered', $delivery->status);
        $this->assertNull($delivery->next_attempt_at);
        // Still visible to the merchant, who can fix their endpoint and
        // replay it. A dead letter is not a dropped event.
        $this->assertTrue($delivery->isRetryable() === false);
        $this->assertNotNull($delivery->failed_at);
    }

    #[Test]
    public function a_persistently_dead_endpoint_disables_the_subscription(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);
        Queue::fake();

        $threshold = (int) config('kavo.webhooks.outbound.disable_after_consecutive_failures');
        $backoff = (array) config('kavo.webhooks.outbound.backoff');

        for ($n = 0; $n < $threshold; $n++) {
            $delivery = $this->deliver();

            for ($i = 0; $i <= count($backoff); $i++) {
                $delivery->update(['status' => 'pending']);
                (new DeliverOutboundWebhook($this->tenant->getKey(), $delivery->getKey()))->handle(
                    app(Factory::class),
                    app(TenantContext::class),
                    app(TenantDatabaseSession::class),
                );
            }

            if ($this->subscription->refresh()->disabled_at !== null) {
                break;
            }
        }

        $this->subscription->refresh();

        // Hammering a dead URL forever helps nobody. It is switched off and
        // the merchant is told.
        $this->assertNotNull($this->subscription->disabled_at);
        $this->assertFalse($this->subscription->is_active);
    }

    #[Test]
    public function a_manual_retry_puts_a_dead_lettered_delivery_back_in_flight(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);
        Queue::fake();

        $delivery = $this->deliver();
        $delivery->update(['status' => 'dead_lettered', 'failed_at' => now()]);

        app(WebhookDispatcher::class)->retry($delivery);

        $this->assertSame('pending', $delivery->refresh()->status);
        $this->assertNull($delivery->next_attempt_at);
        Queue::assertPushed(DeliverOutboundWebhook::class);
    }

    #[Test]
    public function a_disabled_subscription_receives_nothing(): void
    {
        $this->subscription->update(['is_active' => false]);

        $count = app(WebhookDispatcher::class)->dispatch($this->tenant, 'order.placed', ['id' => 'ord_2']);

        $this->assertSame(0, $count);
        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    #[Test]
    public function only_subscribed_event_types_are_delivered(): void
    {
        $count = app(WebhookDispatcher::class)->dispatch($this->tenant, 'order.refunded', ['id' => 'ord_3']);

        $this->assertSame(0, $count);
        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    #[Test]
    public function deliveries_do_not_leak_between_tenants(): void
    {
        $this->deliver();

        $other = Tenant::factory()->create();
        $this->actingAsTenant($other);

        $this->assertSame(0, WebhookDelivery::query()->count());
    }
}
