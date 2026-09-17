<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Platform\Billing\Listeners\ApplyGatewaySettlement;
use App\Modules\Platform\Billing\Models\PaymentIntent;
use App\Modules\Platform\Billing\Payments\Money;
use App\Modules\Platform\Billing\Payments\PaymentRail;
use App\Modules\Platform\Billing\Payments\PaymentRequest;
use App\Modules\Platform\Billing\Payments\PaymentService;
use App\Modules\Platform\Identity\Models\Tenant;
use App\Modules\Platform\Webhooks\Models\WebhookEvent;
use App\Shared\Enums\PaymentStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which tenant a provider callback settles.
 *
 * This is the one lookup in the system that legitimately spans tenants: a
 * webhook arrives with no session, no tenant bound and no idea our tenants
 * exist, so the tenant has to be discovered from the payload. It therefore
 * runs on the schema-owner connection, where neither the Eloquent global
 * scope nor row-level security is watching — a mis-resolution here is not
 * caught by either isolation layer, and everything downstream then runs bound
 * to the wrong tenant.
 *
 * The fixtures are committed rather than transactional. A row created inside
 * the test's own transaction is invisible to the owner connection, so a
 * transactional fixture would make the code under test see nothing and the
 * tests pass for the wrong reason.
 */
final class SettlementRoutingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ends the transaction RefreshDatabase opened; tearDown truncates
        // instead. See the class docblock for why this suite cannot be
        // transactional.
        DB::rollBack();
    }

    protected function tearDown(): void
    {
        $this->truncateCommittedFixtures();

        parent::tearDown();
    }

    private function truncateCommittedFixtures(): void
    {
        DB::connection(config('kavo.tenancy.owner_connection'))->unprepared(
            'truncate payment_events, payment_intents, webhook_events, tenant_user, users, tenants restart identity cascade',
        );
    }

    /**
     * The hijack `public_reference` exists to prevent.
     *
     * Two tenants may legitimately both have an `order-1001` — the schema
     * allows it deliberately. If a settlement resolved on that reference,
     * whoever registered it first would collect the other's money: their
     * intent would be marked paid, the victim's would stay unpaid, and the
     * provider's full payload — customer name, phone, everything it sends —
     * would land in a `payment_events` row the attacker reads back over the
     * API.
     *
     * The attacker's intent is created first on purpose. It is the row an
     * unordered `limit 1` finds, so this test fails against a lookup that
     * matches on the merchant's reference.
     */
    #[Test]
    public function a_settlement_reaches_only_the_tenant_whose_public_reference_it_carries(): void
    {
        $attacker = Tenant::factory()->create();
        $victim = Tenant::factory()->create();

        $theirs = $this->issue($attacker, 'order-1001');
        $mine = $this->issue($victim, 'order-1001');

        $this->assertLessThan($mine->getKey(), $theirs->getKey(), 'the attacker must hold the older row');

        // A webhook arrives with nothing bound, which is the whole problem.
        $this->forgetTenant();

        app(ApplyGatewaySettlement::class)->handle($this->settlement($mine->public_reference));

        $settled = $this->asTenant($victim, fn () => PaymentIntent::query()->find($mine->getKey()));
        $untouched = $this->asTenant($attacker, fn () => PaymentIntent::query()->find($theirs->getKey()));

        $this->assertSame(PaymentStatus::Succeeded, $settled->status);
        $this->assertNotNull($settled->settled_at);

        $this->assertSame(PaymentStatus::AwaitingOfflinePayment, $untouched->status);
        $this->assertNull($untouched->settled_at);

        // And nothing of the provider's payload became readable by the other
        // tenant: the attacker still has only their own issuance event, with
        // no gateway body attached.
        $theirEvents = $this->asTenant($attacker, fn () => $untouched->events()->orderBy('id')->get());

        $this->assertCount(1, $theirEvents);
        $this->assertSame(PaymentStatus::AwaitingOfflinePayment, $theirEvents[0]->to_status);
        $this->assertSame([], $theirEvents[0]->payload);

        $myEvents = $this->asTenant($victim, fn () => $settled->events()->orderBy('id')->get());

        $this->assertCount(2, $myEvents);
        $this->assertSame('evt-settle-1001', $myEvents[1]->external_event_id);
        $this->assertSame('Mona Adel', $myEvents[1]->payload['customer']['name']);
    }

    /**
     * A settlement for a reference nobody owns changes nothing, rather than
     * settling whatever happens to be closest.
     */
    #[Test]
    public function an_unknown_public_reference_settles_nothing(): void
    {
        $tenant = Tenant::factory()->create();
        $intent = $this->issue($tenant, 'order-1001');

        $this->forgetTenant();

        app(ApplyGatewaySettlement::class)->handle($this->settlement('kv_'.Str::lower((string) Str::ulid())));

        $this->assertSame(
            PaymentStatus::AwaitingOfflinePayment,
            $this->asTenant($tenant, fn () => PaymentIntent::query()->find($intent->getKey()))->status,
        );
    }

    /**
     * The unique index makes this unreachable, which is why the guard is worth
     * keeping: an index lost to a bad migration would otherwise turn every
     * contested reference into a coin toss between two tenants, decided on a
     * connection that bypasses row-level security.
     */
    #[Test]
    public function an_ambiguous_public_reference_settles_nothing(): void
    {
        $owner = DB::connection(config('kavo.tenancy.owner_connection'));
        $owner->statement('alter table payment_intents drop constraint payment_intents_public_reference_unique');

        try {
            $first = Tenant::factory()->create();
            $second = Tenant::factory()->create();

            $theirs = $this->issue($first, 'order-1001');
            $mine = $this->issue($second, 'order-1001');

            $owner->table('payment_intents')->where('id', $theirs->getKey())
                ->update(['public_reference' => $mine->public_reference]);

            $this->forgetTenant();
            $log = Log::spy();

            app(ApplyGatewaySettlement::class)->handle($this->settlement($mine->public_reference));

            // Neither one. Picking either is a guess, and one of the two
            // guesses pays the wrong merchant.
            foreach ([[$first, $theirs], [$second, $mine]] as [$tenant, $intent]) {
                $this->assertSame(
                    PaymentStatus::AwaitingOfflinePayment,
                    $this->asTenant($tenant, fn () => PaymentIntent::query()->find($intent->getKey()))->status,
                );
            }

            $this->assertSame(0, $owner->table('payment_events')->where('external_event_id', 'evt-settle-1001')->count());

            $log->shouldHaveReceived('critical')
                ->withArgs(fn (string $message): bool => str_contains($message, 'Ambiguous settlement reference'))
                ->once();
        } finally {
            // The duplicate has to go before the constraint can come back.
            $this->truncateCommittedFixtures();
            $owner->statement('alter table payment_intents add constraint payment_intents_public_reference_unique unique (public_reference)');
        }
    }

    /**
     * Issue an unpaid reference payment for a tenant — the state a provider
     * callback later resolves.
     */
    private function issue(Tenant $tenant, string $reference): PaymentIntent
    {
        return $this->asTenant($tenant, fn (): PaymentIntent => app(PaymentService::class)->charge(
            new PaymentRequest(
                reference: $reference,
                publicReference: 'kv_'.Str::lower((string) Str::ulid()),
                amount: Money::egp(49900),
                rail: PaymentRail::Reference,
                customerName: 'Mona Adel',
                customerEmail: 'mona@example.test',
                customerPhone: '+201000000001',
            ),
        ));
    }

    private function settlement(string $publicReference): WebhookEvent
    {
        return new WebhookEvent([
            'provider' => 'fake',
            'external_event_id' => 'evt-settle-1001',
            'event_type' => 'payment.settled',
            'payload' => [
                'reference' => $publicReference,
                'status' => PaymentStatus::Succeeded->value,
                'amount_cents' => 49900,
                'gateway_reference' => 'fake_settled_1001',
                'event_id' => 'evt-settle-1001',
                // The kind of thing that must never land in another tenant's
                // readable history.
                'customer' => ['name' => 'Mona Adel', 'phone' => '+201000000001'],
            ],
        ]);
    }
}
