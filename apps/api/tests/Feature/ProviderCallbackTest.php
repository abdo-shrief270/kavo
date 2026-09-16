<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Platform\Domains\Models\Domain;
use App\Modules\Platform\Identity\Models\Tenant;
use App\Modules\Platform\Webhooks\Jobs\ProcessInboundWebhook;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The endpoints only a third party ever calls.
 *
 * None of these is exercised by using the product, and each one fails closed,
 * which is the dangerous combination: a 403 here is indistinguishable from
 * "no customer has paid yet" or "nobody has added a custom domain yet" until
 * someone goes looking. Three of them were wrong, and all three were silent.
 */
final class ProviderCallbackTest extends TestCase
{
    private const FAWRY_KEY = 'test-fawry-security-key';

    private const PAYMOB_SECRET = 'test-paymob-hmac-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.fawry.security_key', self::FAWRY_KEY);
        config()->set('services.paymob.hmac_secret', self::PAYMOB_SECRET);
    }

    // --------------------------------------------------------------- Fawry

    /** @return array<string, mixed> */
    private function fawryPayload(array $overrides = []): array
    {
        return $overrides + [
            'requestId' => 'req-1',
            'fawryRefNumber' => '9988776655',
            'merchantRefNumber' => 'kv_01hzzz',
            'paymentAmount' => 499.00,
            'orderAmount' => 499.00,
            'orderStatus' => 'PAID',
            'paymentMethod' => 'PAYATFAWRY',
            'paymentRefrenceNumber' => '',
        ];
    }

    /** @param array<string, mixed> $payload */
    private function fawrySignature(array $payload): string
    {
        return hash('sha256', implode('', [
            (string) $payload['fawryRefNumber'],
            (string) $payload['merchantRefNumber'],
            number_format((float) $payload['paymentAmount'], 2, '.', ''),
            number_format((float) $payload['orderAmount'], 2, '.', ''),
            (string) $payload['orderStatus'],
            (string) $payload['paymentMethod'],
            (string) $payload['paymentRefrenceNumber'],
            self::FAWRY_KEY,
        ]));
    }

    /** @param array<string, mixed> $payload */
    private function deliverCallback(string $provider, array $payload, string $query = ''): TestResponse
    {
        return $this->call(
            'POST',
            '/webhooks/'.$provider.$query,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Without a verifier registered for the provider the endpoint 404s, and a
     * reference payment has no other way to become paid: the customer pays at
     * a kiosk with no connection to us at all.
     */
    #[Test]
    public function a_correctly_signed_fawry_settlement_is_accepted(): void
    {
        Queue::fake();

        $payload = $this->fawryPayload();
        $payload['messageSignature'] = $this->fawrySignature($payload);

        $this->deliverCallback('fawry', $payload)->assertStatus(202);

        $this->assertDatabaseHas('webhook_events', [
            'provider' => 'fawry',
            // Reference plus status: one payment produces a PAID callback and
            // later possibly a REFUNDED one, and both have to get through.
            'external_event_id' => '9988776655:PAID',
            'event_type' => 'PAID',
            'signature_valid' => true,
        ]);

        Queue::assertPushed(ProcessInboundWebhook::class);
    }

    #[Test]
    public function a_fawry_callback_with_a_tampered_amount_is_rejected(): void
    {
        $payload = $this->fawryPayload();
        $payload['messageSignature'] = $this->fawrySignature($payload);

        // Signed for 499.00, delivered as 4.99.
        $payload['paymentAmount'] = 4.99;

        $this->deliverCallback('fawry', $payload)->assertStatus(401);
        $this->assertDatabaseCount('webhook_events', 0);
    }

    #[Test]
    public function a_fawry_callback_with_no_signature_is_rejected(): void
    {
        $this->deliverCallback('fawry', $this->fawryPayload())->assertStatus(401);
    }

    /**
     * Fawry signs amounts to two decimals. An integer amount serialising as
     * "499" instead of "499.00" is the kind of mismatch that only shows up
     * against the real provider.
     */
    #[Test]
    public function fawry_amounts_are_signed_to_two_decimals(): void
    {
        Queue::fake();

        $payload = $this->fawryPayload(['paymentAmount' => 499, 'orderAmount' => 499]);
        $payload['messageSignature'] = $this->fawrySignature($payload);

        $this->deliverCallback('fawry', $payload)->assertStatus(202);
    }

    // -------------------------------------------------------------- Paymob

    /** @return array<string, mixed> */
    private function paymobPayload(): array
    {
        return [
            'type' => 'TRANSACTION',
            'obj' => [
                'id' => 4242,
                'amount_cents' => 49900,
                'created_at' => '2026-09-16T10:00:00.000000',
                'currency' => 'EGP',
                'error_occured' => false,
                'has_parent_transaction' => false,
                'integration_id' => 777,
                'is_3d_secure' => true,
                'is_auth' => false,
                'is_capture' => false,
                'is_refunded' => false,
                'is_standalone_payment' => true,
                'is_voided' => false,
                'order' => ['id' => 1234, 'merchant_order_id' => 'kv_01hzzz'],
                'owner' => 99,
                'pending' => false,
                'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
                'success' => true,
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function paymobHmac(array $payload): string
    {
        $o = $payload['obj'];
        $bool = static fn (bool $v): string => $v ? 'true' : 'false';

        // Paymob's documented order, which is not the payload's order.
        $concatenated = implode('', [
            (string) $o['amount_cents'],
            $o['created_at'],
            $o['currency'],
            $bool($o['error_occured']),
            $bool($o['has_parent_transaction']),
            (string) $o['id'],
            (string) $o['integration_id'],
            $bool($o['is_3d_secure']),
            $bool($o['is_auth']),
            $bool($o['is_capture']),
            $bool($o['is_refunded']),
            $bool($o['is_standalone_payment']),
            $bool($o['is_voided']),
            (string) $o['order']['id'],
            (string) $o['owner'],
            $bool($o['pending']),
            $o['source_data']['pan'],
            $o['source_data']['sub_type'],
            $o['source_data']['type'],
            $bool($o['success']),
        ]);

        return hash_hmac('sha512', $concatenated, self::PAYMOB_SECRET);
    }

    #[Test]
    public function a_correctly_signed_paymob_callback_is_accepted(): void
    {
        Queue::fake();

        $payload = $this->paymobPayload();

        $this->deliverCallback('paymob', $payload, '?hmac='.$this->paymobHmac($payload))->assertStatus(202);

        $this->assertDatabaseHas('webhook_events', [
            'provider' => 'paymob',
            'external_event_id' => '4242',
            'event_type' => 'TRANSACTION',
            'signature_valid' => true,
        ]);
    }

    /**
     * The shape that was in place before: HMAC-SHA256 over the raw body, in a
     * header. Paymob never sends that, so every genuine card settlement was
     * being rejected.
     */
    #[Test]
    public function a_raw_body_hmac_is_not_what_paymob_signs(): void
    {
        $payload = $this->paymobPayload();
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/webhooks/paymob',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_PAYMOB_SIGNATURE' => hash_hmac('sha256', $body, self::PAYMOB_SECRET),
            ],
            content: $body,
        )->assertStatus(401);
    }

    #[Test]
    public function a_paymob_callback_whose_amount_was_edited_in_flight_is_rejected(): void
    {
        $payload = $this->paymobPayload();
        $hmac = $this->paymobHmac($payload);

        $payload['obj']['amount_cents'] = 1;

        $this->deliverCallback('paymob', $payload, '?hmac='.$hmac)->assertStatus(401);
    }

    // ------------------------------------------------------------- TLS ask

    /**
     * Caddy's `ask` is a bare GET: it cannot send a header, so a token
     * expected in one is a token that never arrives, and every custom domain
     * silently fails to get a certificate.
     */
    #[Test]
    public function caddy_can_present_the_ask_token_the_only_way_it_can_send_it(): void
    {
        config()->set('kavo.tls_ask_token', 'ask-token');

        $tenant = Tenant::factory()->create();
        $this->asTenant($tenant, fn () => Domain::factory()->active()->create([
            'tenant_id' => $tenant->getKey(),
            'hostname' => 'shop.example.com',
        ]));

        // Exactly what Caddy sends: the configured query string plus domain.
        $this->get('/internal/tls-ask?token=ask-token&domain=shop.example.com')->assertStatus(200);
    }

    #[Test]
    public function the_ask_endpoint_refuses_without_the_token(): void
    {
        config()->set('kavo.tls_ask_token', 'ask-token');

        $this->get('/internal/tls-ask?domain=shop.example.com')->assertStatus(403);
        $this->get('/internal/tls-ask?token=wrong&domain=shop.example.com')->assertStatus(403);
    }

    #[Test]
    public function the_ask_endpoint_refuses_a_hostname_nobody_registered(): void
    {
        config()->set('kavo.tls_ask_token', 'ask-token');

        // The gate that actually matters: with the token known, an unknown
        // hostname still gets no certificate.
        $this->get('/internal/tls-ask?token=ask-token&domain=not-ours.example.com')->assertStatus(404);
    }

    #[Test]
    public function an_unconfigured_ask_token_refuses_everything(): void
    {
        config()->set('kavo.tls_ask_token', '');

        $this->get('/internal/tls-ask?token=&domain=shop.example.com')->assertStatus(403);
    }
}
