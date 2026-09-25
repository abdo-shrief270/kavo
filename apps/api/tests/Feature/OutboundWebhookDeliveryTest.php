<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Platform\Identity\Models\Tenant;
use App\Modules\Platform\Webhooks\Jobs\DeliverOutboundWebhook;
use App\Modules\Platform\Webhooks\Models\WebhookDelivery;
use App\Modules\Platform\Webhooks\Models\WebhookSubscription;
use App\Modules\Platform\Webhooks\Services\WebhookDispatcher;
use App\Shared\Contracts\ResolvesHosts;
use App\Shared\Http\PublicEndpointGuard;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantDatabaseSession;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeHostResolver;
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

    private FakeHostResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        // Deliveries now check where the destination actually points, and
        // `.test` names resolve nowhere. The resolver is faked rather than
        // the check disabled, so the rules stay live in these tests too.
        $this->resolver = new FakeHostResolver;
        $this->app->instance(ResolvesHosts::class, $this->resolver);

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
        app(WebhookDispatcher::class)->publish($this->tenant, 'order.placed', ['id' => 'ord_1']);

        return WebhookDelivery::query()->latest('id')->firstOrFail();
    }

    private function attempt(WebhookDelivery $delivery): void
    {
        (new DeliverOutboundWebhook($this->tenant->getKey(), $delivery->getKey()))->handle(
            app(Factory::class),
            app(TenantContext::class),
            app(TenantDatabaseSession::class),
            app(PublicEndpointGuard::class),
        );
    }

    #[Test]
    public function a_successful_delivery_is_signed_and_recorded(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $delivery = $this->deliver();
        $this->attempt($delivery);

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
        $this->attempt($delivery);

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
        $this->attempt($delivery);

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
            $this->attempt($delivery);
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
                $this->attempt($delivery);
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

        $count = app(WebhookDispatcher::class)->publish($this->tenant, 'order.placed', ['id' => 'ord_2']);

        $this->assertSame(0, $count);
        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    #[Test]
    public function only_subscribed_event_types_are_delivered(): void
    {
        $count = app(WebhookDispatcher::class)->publish($this->tenant, 'order.refunded', ['id' => 'ord_3']);

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

    // ---------------------------------------------------------------- SSRF

    /**
     * The endpoint was public when the tenant registered it and points at the
     * cloud metadata service by the time we deliver. Only a check at send time
     * catches that.
     */
    #[Test]
    public function a_destination_that_has_moved_to_link_local_space_is_refused_without_a_request(): void
    {
        Http::fake(['*' => Http::response('super-secret-iam-credentials', 200)]);
        $this->resolver->answer('merchant.example.test', '169.254.169.254');

        $delivery = $this->deliver();
        $this->attempt($delivery);

        Http::assertNothingSent();

        $delivery->refresh();

        // Dead-lettered, not retried: nothing about this destination improves
        // by trying again in thirty seconds.
        $this->assertSame('dead_lettered', $delivery->status);
        $this->assertStringContainsString('not publicly routable', (string) $delivery->response_body);
        $this->assertStringNotContainsString('iam-credentials', (string) $delivery->response_body);
    }

    /**
     * A name answering with a good address and a bad one passes any check that
     * stops at the first success.
     */
    #[Test]
    public function every_address_a_host_answers_with_has_to_pass(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->resolver->answer('merchant.example.test', '93.184.216.34', '10.0.0.7');

        $delivery = $this->deliver();
        $this->attempt($delivery);

        Http::assertNothingSent();
        $this->assertSame('dead_lettered', $delivery->refresh()->status);
    }

    /**
     * The request itself has to refuse a redirect and go to the address that
     * was checked, or the check is advisory: a 302 to 169.254.169.254 puts the
     * metadata service's response into response_body, which the tenant reads
     * back over the deliveries API.
     */
    #[Test]
    public function the_request_follows_no_redirects_and_is_pinned_to_the_checked_address(): void
    {
        $seen = [];

        Http::fake(function ($request, array $options) use (&$seen) {
            $seen = $options;

            return Http::response('ok', 200);
        });

        $this->resolver->answer('merchant.example.test', '93.184.216.34');

        $this->attempt($this->deliver());

        $this->assertFalse($seen['allow_redirects'] ?? null, 'redirects must not be followed');

        if (defined('CURLOPT_RESOLVE')) {
            $this->assertSame(
                ['merchant.example.test:443:93.184.216.34'],
                $seen['curl'][CURLOPT_RESOLVE] ?? null,
            );
        }
    }

    #[Test]
    public function a_subscription_pointing_inside_the_network_is_refused_at_registration(): void
    {
        $user = User::factory()->create();
        $this->tenant->users()->attach($user, ['role' => 'owner', 'joined_at' => now()]);

        $this->resolver->answer('internal.example.test', '127.0.0.1');

        $this->actingAs($user)
            ->withHeaders(['X-Tenant' => $this->tenant->slug])
            ->postJson('/api/webhooks/subscriptions', [
                'url' => 'https://internal.example.test/hooks',
                'event_types' => ['order.placed'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url');

        $this->assertSame(1, WebhookSubscription::query()->count());
    }

    /**
     * The address families are checked separately by the filter flags, and a
     * host with a harmless A record and a link-local AAAA record is the
     * obvious way past a check that only looks at one of them.
     */
    #[Test]
    public function an_ipv6_literal_inside_reserved_space_is_refused(): void
    {
        $guard = app(PublicEndpointGuard::class);

        foreach ([
            'https://[::1]/hooks',
            'https://[fe80::1]/hooks',
            'https://[fc00::1]/hooks',
            'https://[::ffff:169.254.169.254]/hooks',
            'https://[64:ff9b::a00:1]/hooks',
        ] as $url) {
            $this->assertFalse($guard->allows($url), $url.' should be refused');
        }
    }

    #[Test]
    public function the_shapes_a_url_can_take_to_look_public_are_refused(): void
    {
        $guard = app(PublicEndpointGuard::class);

        $this->resolver->answer('nowhere.example.test');

        foreach ([
            'http://merchant.example.test/hooks' => 'plaintext',
            'https://user:pass@merchant.example.test/hooks' => 'embedded credentials',
            'https://nowhere.example.test/hooks' => 'a name that does not resolve',
            'https://127.0.0.1/hooks' => 'loopback',
            'https://169.254.169.254/latest/meta-data/' => 'cloud metadata',
            'https://10.0.0.7/hooks' => 'private space',
            'https://100.64.0.1/hooks' => 'carrier-grade NAT',
            'https://198.18.0.1/hooks' => 'benchmarking space',
            'not a url at all' => 'an unparseable string',
        ] as $url => $why) {
            $this->assertFalse($guard->allows($url), $why.' should be refused: '.$url);
        }

        $this->assertTrue($guard->allows('https://merchant.example.test/hooks'));
        $this->assertTrue($guard->allows('https://93.184.216.34/hooks'));
    }
}
