<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Platform\Billing\Models\PaymentIntent;
use App\Modules\Platform\Billing\Payments\DuplicateSettlement;
use App\Modules\Platform\Billing\Payments\Money;
use App\Modules\Platform\Billing\Payments\PaymentRail;
use App\Modules\Platform\Billing\Payments\PaymentRequest;
use App\Modules\Platform\Billing\Payments\PaymentService;
use App\Modules\Platform\Billing\Payments\SettlementNotice;
use App\Modules\Platform\Identity\Models\Tenant;
use App\Shared\Enums\PaymentStatus;
use App\Shared\Events\PaymentSettled;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Payments, with the offline rail treated as the primary case.
 *
 * ADR 0001 §6 warned that modelling only card authorise/capture makes Fawry a
 * rewrite. These tests exist to keep that true: the reference rail is exercised
 * through its whole lifecycle — issued, unpaid, settled by webhook, or expired —
 * because none of those states exist on a card rail and all of them are normal
 * here.
 */
final class PaymentsTest extends TestCase
{
    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);

        $this->actingAsTenant($this->tenant);
    }

    private function request(PaymentRail $rail, array $metadata = [], ?string $reference = null): PaymentRequest
    {
        return new PaymentRequest(
            reference: $reference ?? 'order-'.Str::lower(Str::random(8)),
            publicReference: 'kv_'.Str::lower((string) Str::ulid()),
            amount: Money::egp(49900),
            rail: $rail,
            customerName: 'Mona Adel',
            customerEmail: 'mona@example.test',
            customerPhone: '+201000000001',
            metadata: $metadata,
        );
    }

    private function api(string $method, string $uri, array $body = [], array $headers = []): TestResponse
    {
        return $this->actingAs($this->owner)
            ->withHeaders($headers + ['X-Tenant' => $this->tenant->slug])
            ->json($method, $uri, $body);
    }

    // ---------------------------------------------------------------- cards

    #[Test]
    public function a_card_payment_settles_immediately(): void
    {
        $intent = app(PaymentService::class)->charge($this->request(PaymentRail::Card));

        $this->assertSame(PaymentStatus::Succeeded, $intent->status);
        $this->assertNotNull($intent->settled_at);
        $this->assertNull($intent->customerAction());
    }

    #[Test]
    public function a_declined_card_fails_without_becoming_an_open_payment(): void
    {
        $intent = app(PaymentService::class)->charge(
            $this->request(PaymentRail::Card, ['fake_outcome' => 'declined'])
        );

        $this->assertSame(PaymentStatus::Failed, $intent->status);
        $this->assertFalse($intent->isOpen());
        $this->assertNotNull($intent->last_error);
    }

    #[Test]
    public function a_wallet_payment_asks_the_customer_to_complete_a_redirect(): void
    {
        $intent = app(PaymentService::class)->charge($this->request(PaymentRail::Wallet));

        $this->assertSame(PaymentStatus::RequiresAction, $intent->status);
        $this->assertSame('redirect', $intent->customerAction()['type']);
        // Stock is held, not consumed, until the customer actually pays.
        $this->assertTrue($intent->status->reservesRatherThanCommits());
    }

    // ------------------------------------------------------------- vouchers

    /**
     * The case the whole contract is shaped around: a successful call that
     * moves no money. Treating this as a failure would cancel every Fawry
     * order at the moment it was placed.
     */
    #[Test]
    public function a_reference_payment_issues_a_code_and_stays_open(): void
    {
        $intent = app(PaymentService::class)->charge($this->request(PaymentRail::Reference));

        $this->assertSame(PaymentStatus::AwaitingOfflinePayment, $intent->status);
        $this->assertTrue($intent->isOpen());
        $this->assertFalse($intent->status->isFinal());

        $action = $intent->customerAction();
        $this->assertSame('reference', $action['type']);
        $this->assertNotEmpty($action['reference']);

        // Every open intent has a window, or an unpaid reference holds its
        // reservation forever.
        $this->assertNotNull($intent->expires_at);
        $this->assertTrue($intent->expires_at->isFuture());
        $this->assertTrue($intent->status->reservesRatherThanCommits());
    }

    #[Test]
    public function a_reference_payment_is_only_paid_by_a_settlement_callback(): void
    {
        Event::fake([PaymentSettled::class]);

        $intent = app(PaymentService::class)->charge($this->request(PaymentRail::Reference));

        Event::assertNotDispatched(PaymentSettled::class);

        $settled = app(PaymentService::class)->settle(new SettlementNotice(
            reference: $intent->public_reference,
            status: PaymentStatus::Succeeded,
            gatewayReference: 'fawry-ref-1',
            amountCents: 49900,
            externalEventId: 'evt-paid-1',
        ), 'fake');

        $this->assertSame(PaymentStatus::Succeeded, $settled->status);
        $this->assertNotNull($settled->settled_at);

        Event::assertDispatched(
            PaymentSettled::class,
            fn (PaymentSettled $e): bool => $e->succeeded() && $e->from === PaymentStatus::AwaitingOfflinePayment,
        );
    }

    #[Test]
    public function an_unpaid_reference_expires_and_releases_its_reservation(): void
    {
        Event::fake([PaymentSettled::class]);

        $intent = PaymentIntent::factory()->awaitingOfflinePayment()->create([
            'expires_at' => now()->subHour(),
        ]);

        $this->assertTrue($intent->hasExpired());

        $expired = app(PaymentService::class)->expireOverdue();

        $this->assertSame(1, $expired);
        $this->assertSame(PaymentStatus::Expired, $intent->refresh()->status);

        Event::assertDispatched(
            PaymentSettled::class,
            fn (PaymentSettled $e): bool => $e->releasesReservation(),
        );
    }

    #[Test]
    public function an_expired_reference_cannot_be_paid_afterwards(): void
    {
        $intent = PaymentIntent::factory()->awaitingOfflinePayment()->create([
            'status' => PaymentStatus::Expired,
        ]);

        $result = app(PaymentService::class)->settle(new SettlementNotice(
            reference: $intent->public_reference,
            status: PaymentStatus::Succeeded,
            amountCents: $intent->amount_cents,
            externalEventId: 'evt-late',
        ), $intent->gateway);

        // Terminal states are terminal. A late callback must not resurrect an
        // order that was already released.
        $this->assertSame(PaymentStatus::Expired, $result->status);
    }

    // --------------------------------------------------------- settlement

    /**
     * Replay protection has two layers, and this covers the outer one: once an
     * intent is terminal, a repeated callback is a no-op. Providers retry by
     * design, so this is the common case and must not error.
     */
    #[Test]
    public function a_replayed_settlement_on_a_settled_payment_changes_nothing(): void
    {
        $intent = app(PaymentService::class)->charge($this->request(PaymentRail::Reference));

        $notice = new SettlementNotice(
            reference: $intent->public_reference,
            status: PaymentStatus::Succeeded,
            amountCents: 49900,
            externalEventId: 'evt-once',
        );

        $first = app(PaymentService::class)->settle($notice, 'fake');
        $settledAt = $first->settled_at;

        $second = app(PaymentService::class)->settle($notice, 'fake');

        $this->assertSame(PaymentStatus::Succeeded, $second->status);
        $this->assertEquals($settledAt, $second->settled_at);
        // One transition recorded, not two.
        $this->assertCount(2, $intent->refresh()->events()->get());
    }

    /**
     * And the inner layer: a replay that reaches the transition — because the
     * intent is not terminal yet — collides on the unique
     * (intent, external_event_id) constraint. The history is what enforces
     * idempotency, not a check the caller has to remember.
     */
    #[Test]
    public function a_replayed_non_terminal_settlement_collides_on_the_event_history(): void
    {
        $intent = app(PaymentService::class)->charge($this->request(PaymentRail::Reference));

        $notice = new SettlementNotice(
            reference: $intent->public_reference,
            status: PaymentStatus::Processing,
            amountCents: 49900,
            externalEventId: 'evt-processing',
        );

        app(PaymentService::class)->settle($notice, 'fake');

        $this->expectException(DuplicateSettlement::class);

        app(PaymentService::class)->settle($notice, 'fake');
    }

    #[Test]
    public function a_settlement_whose_amount_disagrees_is_refused(): void
    {
        $intent = app(PaymentService::class)->charge($this->request(PaymentRail::Reference));

        $result = app(PaymentService::class)->settle(new SettlementNotice(
            reference: $intent->public_reference,
            status: PaymentStatus::Succeeded,
            // Half what we asked for: either a provider bug or a tampered
            // payload. Neither is applied automatically.
            amountCents: 100,
            externalEventId: 'evt-mismatch',
        ), 'fake');

        $this->assertSame(PaymentStatus::Failed, $result->status);
        $this->assertStringContainsString('mismatch', (string) $result->last_error);
    }

    #[Test]
    public function a_settlement_for_an_unknown_reference_is_ignored_rather_than_guessed(): void
    {
        $result = app(PaymentService::class)->settle(new SettlementNotice(
            reference: 'kv_does-not-exist',
            status: PaymentStatus::Succeeded,
        ), 'fake');

        $this->assertNull($result);
    }

    #[Test]
    public function every_transition_is_recorded(): void
    {
        $intent = app(PaymentService::class)->charge($this->request(PaymentRail::Reference));

        app(PaymentService::class)->settle(new SettlementNotice(
            reference: $intent->public_reference,
            status: PaymentStatus::Succeeded,
            amountCents: 49900,
            externalEventId: 'evt-history',
        ), 'fake');

        $events = $intent->refresh()->events()->orderBy('id')->get();

        // The history explains the state rather than merely agreeing with it,
        // which is what settles a dispute about what the gateway said.
        $this->assertCount(2, $events);
        $this->assertSame(PaymentStatus::AwaitingOfflinePayment, $events[0]->to_status);
        $this->assertSame(PaymentStatus::Succeeded, $events[1]->to_status);
    }

    // ---------------------------------------------------------------- API

    #[Test]
    public function the_api_returns_created_for_a_reference_payment_that_moved_no_money(): void
    {
        $response = $this->api('POST', '/api/payments', [
            'reference' => 'order-9001',
            'amount_cents' => 49900,
            'currency' => 'EGP',
            'rail' => 'reference',
            'customer_name' => 'Mona Adel',
            'customer_email' => 'mona@example.test',
            'customer_phone' => '+201000000001',
        ]);

        // 201, not an error: an issued reference is a created payment.
        $response->assertStatus(201)
            ->assertJsonPath('payment.status', 'awaiting_offline_payment')
            ->assertJsonPath('customer_action.type', 'reference');
    }

    #[Test]
    public function only_configured_rails_are_offered(): void
    {
        $this->api('GET', '/api/payments/rails')
            ->assertOk()
            ->assertJsonPath('rails.0.rail', 'card');
    }

    #[Test]
    public function a_customer_phone_is_required_because_the_offline_rail_needs_it(): void
    {
        $this->api('POST', '/api/payments', [
            'reference' => 'order-9002',
            'amount_cents' => 49900,
            'currency' => 'EGP',
            'rail' => 'reference',
            'customer_name' => 'Mona Adel',
            'customer_email' => 'mona@example.test',
        ])->assertStatus(422)->assertJsonValidationErrors('customer_phone');
    }

    // -------------------------------------------------------- idempotency

    #[Test]
    public function a_retried_checkout_does_not_create_a_second_payment(): void
    {
        $body = [
            'reference' => 'order-9100',
            'amount_cents' => 49900,
            'currency' => 'EGP',
            'rail' => 'card',
            'customer_name' => 'Mona Adel',
            'customer_email' => 'mona@example.test',
            'customer_phone' => '+201000000001',
        ];

        $first = $this->api('POST', '/api/payments', $body, ['Idempotency-Key' => 'key-abc']);
        $second = $this->api('POST', '/api/payments', $body, ['Idempotency-Key' => 'key-abc']);

        $first->assertStatus(201);
        $second->assertStatus(201);
        $this->assertSame('true', $second->headers->get('Idempotent-Replay'));

        // The point: one payment, not two.
        $this->assertSame(1, PaymentIntent::query()->where('reference', 'order-9100')->count());
    }

    /**
     * Reusing a key with a different body is a client bug, and replaying the
     * first response would hide it behind a success.
     */
    #[Test]
    public function reusing_a_key_with_a_different_body_is_refused(): void
    {
        $body = [
            'reference' => 'order-9200',
            'amount_cents' => 49900,
            'currency' => 'EGP',
            'rail' => 'card',
            'customer_name' => 'Mona Adel',
            'customer_email' => 'mona@example.test',
            'customer_phone' => '+201000000001',
        ];

        $this->api('POST', '/api/payments', $body, ['Idempotency-Key' => 'key-xyz'])->assertStatus(201);

        $this->api('POST', '/api/payments', ['...' => 'different'] + $body, ['Idempotency-Key' => 'key-xyz'])
            ->assertStatus(422);
    }

    #[Test]
    public function requests_without_a_key_are_unaffected(): void
    {
        $body = [
            'amount_cents' => 49900,
            'currency' => 'EGP',
            'rail' => 'card',
            'customer_name' => 'Mona Adel',
            'customer_email' => 'mona@example.test',
            'customer_phone' => '+201000000001',
        ];

        $this->api('POST', '/api/payments', ['reference' => 'order-a'] + $body)->assertStatus(201);
        $this->api('POST', '/api/payments', ['reference' => 'order-b'] + $body)->assertStatus(201);

        $this->assertSame(2, PaymentIntent::query()->count());
    }

    // ----------------------------------------------------------- isolation

    #[Test]
    public function payments_do_not_leak_between_tenants(): void
    {
        app(PaymentService::class)->charge($this->request(PaymentRail::Card, reference: 'order-mine'));

        $other = Tenant::factory()->create();
        $this->actingAsTenant($other);

        $this->assertSame(0, PaymentIntent::query()->count());
        $this->assertNull(PaymentIntent::query()->where('reference', 'order-mine')->first());
    }

    /**
     * Two tenants may legitimately both have an order-1001, so settlement
     * matches on reference *within* a tenant.
     */
    #[Test]
    public function two_tenants_can_hold_the_same_reference(): void
    {
        app(PaymentService::class)->charge($this->request(PaymentRail::Card, reference: 'order-1001'));

        $other = Tenant::factory()->create();
        $this->actingAsTenant($other);

        $theirs = app(PaymentService::class)->charge($this->request(PaymentRail::Card, reference: 'order-1001'));

        $this->assertSame('order-1001', $theirs->reference);
        $this->assertSame($other->getKey(), $theirs->tenant_id);
        $this->assertSame(1, PaymentIntent::query()->where('reference', 'order-1001')->count());
    }

    /**
     * The database, not the application, is what keeps the reference the
     * gateway sees unambiguous.
     */
    #[Test]
    public function a_public_reference_cannot_be_reused_by_another_tenant(): void
    {
        $mine = app(PaymentService::class)->charge($this->request(PaymentRail::Card, reference: 'order-1001'));

        $attacker = Tenant::factory()->create();

        $this->expectException(QueryException::class);

        // Wrapped so the constraint violation rolls back to a savepoint rather
        // than poisoning the test's own transaction.
        DB::transaction(fn () => $this->asTenant($attacker, fn () => app(PaymentService::class)->charge(
            new PaymentRequest(
                reference: 'order-1001',
                publicReference: $mine->public_reference,
                amount: Money::egp(49900),
                rail: PaymentRail::Card,
                customerName: 'Mona Adel',
                customerEmail: 'mona@example.test',
                customerPhone: '+201000000001',
            ),
        )));
    }
}
