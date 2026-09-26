<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Commerce\Catalogue\Models\Product;
use App\Modules\Commerce\Catalogue\Models\ProductVariant;
use App\Modules\Commerce\Orders\Models\Order;
use App\Modules\Platform\Billing\Ledger\Enums\LedgerEntryType;
use App\Modules\Platform\Billing\Ledger\Enums\PayoutStatus;
use App\Modules\Platform\Billing\Ledger\Models\LedgerEntry;
use App\Modules\Platform\Billing\Ledger\Models\Payout;
use App\Modules\Platform\Billing\Ledger\Services\LedgerService;
use App\Modules\Platform\Billing\Ledger\Services\PayoutService;
use App\Modules\Platform\Billing\Models\Plan;
use App\Modules\Platform\Billing\Models\PlanFeature;
use App\Modules\Platform\Billing\Payments\Money;
use App\Modules\Platform\Billing\Payments\PaymentGatewayManager;
use App\Modules\Platform\Billing\Payments\PaymentService;
use App\Modules\Platform\Identity\Models\Tenant;
use App\Shared\Enums\PaymentStatus;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * What the platform owes each merchant.
 *
 * The platform is the merchant of record: every customer pays into its own
 * Paymob and Fawry accounts, so every settled order is a debt to the shop that
 * made it, less a 1% commission. Before this existed the money arrived and
 * nothing recorded whose it was.
 *
 * The property under test throughout is that the ledger and reality cannot
 * drift: every credit has a cause, nothing is ever edited, and the balance is
 * summed from the entries rather than stored beside them.
 */
final class MerchantLedgerTest extends TestCase
{
    private Tenant $tenant;

    private User $owner;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        // A tenant with no plan has no orders allowance at all — the engine
        // treats an absent feature as "not included", which is right and is
        // not what any of these tests are about.
        $this->tenant = Tenant::factory()->create(['plan_id' => $this->planAllowingOrders()->getKey()]);
        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);

        $this->actingAsTenant($this->tenant);

        $product = Product::factory()->published()->create(['name' => 'Linen Shirt', 'currency' => 'EGP']);
        $this->variant = ProductVariant::factory()->for($product)->create([
            'sku' => 'LIN-M',
            'options' => ['Size' => 'M'],
            'option_signature' => ProductVariant::signatureFor(['Size' => 'M']),
            'price_cents' => 100_000,
            'stock_on_hand' => 10,
        ]);
    }

    /** Unlimited orders, so the meter never stands between a test and a sale. */
    private function planAllowingOrders(): Plan
    {
        $plan = Plan::factory()->create();

        PlanFeature::query()->create([
            'plan_id' => $plan->getKey(),
            'feature_key' => 'orders',
            'limit_value' => null,
            'overage_behavior' => 'block',
        ]);

        return $plan;
    }

    private function ledger(): LedgerService
    {
        return app(LedgerService::class);
    }

    // ------------------------------------------------------------ the rate

    /**
     * 1%, in basis points and integer arithmetic. A float that has been
     * through arithmetic is not a sum anyone should be charged.
     */
    #[Test]
    public function the_commission_is_one_percent_and_the_fraction_goes_to_the_merchant(): void
    {
        $this->assertSame(1_000, $this->ledger()->commissionOn(new Money(100_000))->amountCents);

        // 1% of 12,345 piastres is 123.45. Truncated, so the merchant keeps
        // the fraction rather than the platform.
        $this->assertSame(123, $this->ledger()->commissionOn(new Money(12_345))->amountCents);

        $this->assertSame(0, $this->ledger()->commissionOn(new Money(50))->amountCents);
    }

    // ----------------------------------------------------------- the sale

    #[Test]
    public function a_settled_order_credits_the_merchant_less_the_commission(): void
    {
        $this->placeAndPayForOrder();

        // 100,000 gross, 1,000 commission, 99,000 owed.
        $this->assertSame(99_000, $this->ledger()->balanceCents($this->tenant));

        $entries = LedgerEntry::query()->orderBy('id')->get();

        $this->assertSame(
            [LedgerEntryType::Sale, LedgerEntryType::Commission],
            $entries->map(fn (LedgerEntry $e) => $e->type)->all(),
        );

        // The sign comes from the type, never from the caller. A commission
        // credited would pay the merchant for being charged.
        $this->assertSame(100_000, $entries[0]->amount_cents);
        $this->assertSame(-1_000, $entries[1]->amount_cents);
    }

    /**
     * Providers retry callbacks. Crediting the same order twice is money
     * invented out of nothing, so the ledger refuses it at the index rather
     * than relying on every caller remembering.
     */
    #[Test]
    public function a_settlement_applied_twice_credits_the_merchant_once(): void
    {
        $order = $this->placeAndPayForOrder();

        $this->ledger()->recordSale($this->tenant, new Money(100_000), 'order', (int) $order->getKey(), 'Order '.$order->reference());

        $this->assertSame(99_000, $this->ledger()->balanceCents($this->tenant));
        $this->assertSame(2, LedgerEntry::query()->count());
    }

    /** The platform earns nothing on a sale that did not happen. */
    #[Test]
    public function a_refund_takes_the_money_back_and_returns_the_commission(): void
    {
        $this->placeAndPayForOrder();
        $this->settle(PaymentStatus::Refunded);

        $this->assertSame(0, $this->ledger()->balanceCents($this->tenant));

        $this->assertSame(
            [LedgerEntryType::Sale, LedgerEntryType::Commission, LedgerEntryType::Refund, LedgerEntryType::CommissionReversed],
            LedgerEntry::query()->orderBy('id')->get()->map(fn (LedgerEntry $e) => $e->type)->all(),
        );
    }

    // --------------------------------------------------------- the ledger

    /**
     * A balance you can edit is a balance nobody can dispute. A correction is
     * a new entry that says who made it, not a change to the one that was
     * wrong.
     */
    #[Test]
    public function an_entry_can_never_be_edited_or_deleted(): void
    {
        $this->placeAndPayForOrder();
        $entry = LedgerEntry::query()->orderBy('id')->firstOrFail();

        try {
            $entry->update(['amount_cents' => 999_999]);
            $this->fail('A ledger entry was edited.');
        } catch (LogicException) {
            // Expected.
        }

        try {
            $entry->delete();
            $this->fail('A ledger entry was deleted.');
        } catch (LogicException) {
            // Expected.
        }

        $this->assertSame(100_000, $entry->refresh()->amount_cents);
    }

    /**
     * Row-level security fails closed, which is right — but "closed" for a SUM
     * is the number zero, and a balance that silently reads zero is one a
     * merchant gets paid.
     */
    #[Test]
    public function the_balance_refuses_to_answer_with_no_tenant_bound(): void
    {
        $this->placeAndPayForOrder();
        $this->forgetTenant();

        $this->expectException(RuntimeException::class);

        $this->ledger()->balanceCents($this->tenant);
    }

    // --------------------------------------------------------- the payout

    /**
     * Debited on creation, not on arrival. A transfer takes days to clear, and
     * a balance that still shows the money invites a second payout of it.
     */
    #[Test]
    public function a_payout_is_debited_when_it_is_created_not_when_it_arrives(): void
    {
        $this->placeAndPayForOrder();

        $payout = app(PayoutService::class)->request($this->tenant, new Money(50_000), 'instapay', ['handle' => 'nadia@instapay']);

        $this->assertSame(PayoutStatus::Pending, $payout->status);
        $this->assertSame(49_000, $this->ledger()->balanceCents($this->tenant));

        app(PayoutService::class)->markPaid($payout);

        // Arriving moves no money: it was already taken off.
        $this->assertSame(49_000, $this->ledger()->balanceCents($this->tenant));
    }

    #[Test]
    public function a_payout_cannot_be_more_than_is_owed(): void
    {
        $this->placeAndPayForOrder();

        $this->expectException(ValidationException::class);

        app(PayoutService::class)->request($this->tenant, new Money(99_001), 'instapay', []);
    }

    #[Test]
    public function a_failed_payout_puts_the_money_back(): void
    {
        $this->placeAndPayForOrder();

        $payouts = app(PayoutService::class);
        $payout = $payouts->request($this->tenant, new Money(99_000), 'bank_transfer', ['iban' => 'EG00000000000000000000000']);

        $this->assertSame(0, $this->ledger()->balanceCents($this->tenant));

        $payouts->markFailed($this->tenant, $payout, 'Account number rejected by the bank.');

        $this->assertSame(99_000, $this->ledger()->balanceCents($this->tenant));
        $this->assertSame(PayoutStatus::Failed, $payout->refresh()->status);
    }

    #[Test]
    public function a_settled_payout_cannot_be_settled_again(): void
    {
        $this->placeAndPayForOrder();

        $payouts = app(PayoutService::class);
        $payout = $payouts->request($this->tenant, new Money(10_000), 'cash', []);
        $payouts->markPaid($payout);

        $this->expectException(ValidationException::class);

        $payouts->markFailed($this->tenant, $payout, 'Changed my mind.');
    }

    // ------------------------------------------------------------- the API

    #[Test]
    public function a_merchant_sees_what_they_are_owed(): void
    {
        $this->placeAndPayForOrder();

        $this->merchant('GET', '/api/balance')
            ->assertOk()
            ->assertJsonPath('balance.amount_cents', 99_000)
            ->assertJsonPath('balance.overdrawn', false)
            ->assertJsonPath('commission_basis_points', 100)
            ->assertJsonCount(2, 'entries');
    }

    /** And nothing of anybody else's. */
    #[Test]
    public function a_merchant_never_sees_another_shops_ledger(): void
    {
        $this->placeAndPayForOrder();

        $other = Tenant::factory()->create();
        $this->asTenant($other, fn () => LedgerEntry::factory()->of(500_000)->create());

        $this->actingAsTenant($this->tenant);

        $this->merchant('GET', '/api/balance')
            ->assertOk()
            ->assertJsonPath('balance.amount_cents', 99_000);
    }

    #[Test]
    public function the_platform_pays_a_merchant_and_the_balance_drops(): void
    {
        $this->placeAndPayForOrder();

        $created = $this->admin('POST', '/api/admin/tenants/'.$this->tenant->getKey().'/payouts', [
            'amount_cents' => 99_000,
            'method' => 'instapay',
            'destination' => ['handle' => 'nadia@instapay'],
            'notes' => 'September settlement',
        ]);

        $created->assertStatus(201)->assertJsonPath('payout.status', 'pending');

        // A bank account is not something a list endpoint needs to publish.
        $created->assertJsonMissingPath('payout.destination');

        $this->actingAsTenant($this->tenant);
        $this->merchant('GET', '/api/balance')->assertJsonPath('balance.amount_cents', 0);

        $this->admin('POST', '/api/admin/tenants/'.$this->tenant->getKey().'/payouts/'.$created->json('payout.id').'/settle', [
            'status' => 'paid',
        ])->assertOk()->assertJsonPath('payout.status', 'paid');

        // Every platform-scope action on a merchant's money is on the trail.
        // Read with no tenant bound, which is what the audit_logs policy shows
        // platform entries to.
        $this->forgetTenant();
        $this->assertDatabaseHas('audit_logs', ['action' => 'payout.created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payout.paid']);
    }

    /** A merchant is not a platform administrator. */
    #[Test]
    public function a_merchant_cannot_reach_the_payout_console(): void
    {
        $this->actingAs($this->owner)
            ->json('GET', '/api/admin/balances')
            ->assertStatus(403);
    }

    // -------------------------------------------------------------- helpers

    /** Place an order for one 1,000.00 EGP shirt and pay for it on a card. */
    private function placeAndPayForOrder(): Order
    {
        $token = (string) $this->storefront('POST', '/cart/items', [
            'variant_id' => $this->variant->getKey(),
            'quantity' => 1,
        ])->assertStatus(201)->json('cart.token');

        $this->storefront('POST', '/checkout', [
            'customer_name' => 'Nadia Hassan',
            'customer_email' => 'nadia@example.test',
            'customer_phone' => '+201000000001',
            'rail' => 'card',
        ], $token)->assertStatus(201);

        $this->actingAsTenant($this->tenant);

        return Order::query()->latest('id')->firstOrFail();
    }

    private function settle(PaymentStatus $status): void
    {
        $intent = Order::query()->latest('id')->firstOrFail()->paymentIntent;

        $notice = app(PaymentGatewayManager::class)->gateway('fake')->parseSettlement([
            'reference' => $intent->public_reference,
            'status' => $status->value,
            'amount_cents' => $intent->amount_cents,
            'event_id' => 'evt-'.$status->value,
        ]);

        app(PaymentService::class)->settle($notice, 'fake');
    }

    private function storefront(string $method, string $path, array $body = [], ?string $token = null): TestResponse
    {
        return $this->withHeaders($token === null ? [] : ['X-Cart-Token' => $token])->json(
            $method,
            'http://'.$this->tenant->slug.'.'.config('kavo.root_domain').'/api/storefront'.$path,
            $body,
        );
    }

    private function merchant(string $method, string $uri, array $body = []): TestResponse
    {
        return $this->actingAs($this->owner)
            ->withHeaders(['X-Tenant' => $this->tenant->slug])
            ->json($method, $uri, $body);
    }

    private function admin(string $method, string $uri, array $body = []): TestResponse
    {
        $staff = User::factory()->create(['is_platform_admin' => true]);

        return $this->actingAs($staff)->json($method, $uri, $body);
    }
}
