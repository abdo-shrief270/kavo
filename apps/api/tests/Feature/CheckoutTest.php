<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Commerce\Catalogue\Models\Product;
use App\Modules\Commerce\Catalogue\Models\ProductVariant;
use App\Modules\Commerce\Orders\Enums\InventoryState;
use App\Modules\Commerce\Orders\Enums\OrderStatus;
use App\Modules\Commerce\Orders\Models\Cart;
use App\Modules\Commerce\Orders\Models\Order;
use App\Modules\Commerce\Orders\Services\OrderSettlement;
use App\Modules\Platform\Billing\Models\Plan;
use App\Modules\Platform\Billing\Models\PlanFeature;
use App\Modules\Platform\Billing\Payments\PaymentGatewayManager;
use App\Modules\Platform\Billing\Payments\PaymentService;
use App\Modules\Platform\Identity\Models\Tenant;
use App\Modules\Platform\Webhooks\Models\WebhookDelivery;
use App\Modules\Platform\Webhooks\Models\WebhookSubscription;
use App\Shared\Contracts\Entitlements;
use App\Shared\Enums\PaymentStatus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\DecliningGateway;
use Tests\TestCase;

/**
 * Cart, checkout and the stock lifecycle behind them.
 *
 * The property under test throughout is that stock is *held* between placing
 * an order and paying for it, and that every way that hold can end — paid,
 * expired, failed, cancelled — ends it exactly once. Egypt's largest payment
 * rail issues a reference that is paid at a kiosk up to 72 hours later, so
 * "ordered but unpaid" is a multi-day state here rather than a few seconds,
 * and a reservation that leaks either oversells the shop or sells it out to
 * customers who never paid.
 */
final class CheckoutTest extends TestCase
{
    private Tenant $tenant;

    private User $owner;

    private Product $product;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['plan_id' => $this->planAllowingOrders(null)->getKey()]);
        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);

        $this->actingAsTenant($this->tenant);

        $this->product = Product::factory()->published()->create(['name' => 'Linen Shirt', 'currency' => 'EGP']);
        $this->variant = ProductVariant::factory()->for($this->product)->create([
            'sku' => 'LIN-M',
            'options' => ['Size' => 'M'],
            'option_signature' => ProductVariant::signatureFor(['Size' => 'M']),
            'price_cents' => 89900,
            'stock_on_hand' => 3,
            'stock_reserved' => 0,
        ]);

        // Outbound delivery is the Webhooks module's business and has its own
        // suite; here the question is only whether checkout asks for it.
        Queue::fake();
    }

    // --------------------------------------------------------------- basket

    #[Test]
    public function a_cart_prices_itself_at_todays_prices_not_yesterdays(): void
    {
        $added = $this->storefront('POST', '/cart/items', ['variant_id' => $this->variant->getKey(), 'quantity' => 2]);

        $added->assertStatus(201)
            ->assertJsonPath('cart.subtotal_cents', 179800)
            ->assertJsonPath('cart.checkout_ready', true);

        $token = $added->json('cart.token');

        // The merchant puts it on offer while the basket sits there.
        $this->variant->update(['price_cents' => 49900]);

        $this->storefront('GET', '/cart', token: $token)
            ->assertOk()
            ->assertJsonPath('cart.subtotal_cents', 99800);
    }

    /**
     * A cart built the day before a product was unpublished must not be a way
     * around the merchant withdrawing it — nor a way to read the name and
     * price of something never published.
     */
    #[Test]
    public function a_cart_will_not_take_something_that_is_not_for_sale(): void
    {
        $draft = Product::factory()->create();
        $hidden = ProductVariant::factory()->for($draft)->create();

        $this->storefront('POST', '/cart/items', ['variant_id' => $hidden->getKey()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('variant_id');
    }

    #[Test]
    public function a_cart_will_not_take_more_than_the_shelf_holds(): void
    {
        $this->storefront('POST', '/cart/items', ['variant_id' => $this->variant->getKey(), 'quantity' => 4])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity');

        $this->assertSame(0, $this->variant->refresh()->stock_reserved);
    }

    /**
     * Cart lines are found through the cart, never by id alone. This endpoint
     * is anonymous, so binding on the id would let anyone holding any cart
     * token edit anybody else's basket — and row-level security cannot help,
     * because both baskets belong to the same shop.
     */
    #[Test]
    public function one_shopper_cannot_reach_into_anothers_basket(): void
    {
        $mine = $this->storefront('POST', '/cart/items', ['variant_id' => $this->variant->getKey()]);
        $theirs = $this->storefront('POST', '/cart/items', ['variant_id' => $this->variant->getKey()]);

        $theirLine = $theirs->json('cart.items.0.id');

        $this->storefront('DELETE', '/cart/items/'.$theirLine, token: $mine->json('cart.token'))
            ->assertStatus(404);
    }

    // ------------------------------------------------------------- checkout

    /** A card settles inside the request, so the goods leave the shelf now. */
    #[Test]
    public function a_card_checkout_takes_the_stock_off_the_shelf(): void
    {
        $token = $this->fillCart(2);

        $response = $this->storefront('POST', '/checkout', $this->details('card'), token: $token);

        $response->assertStatus(201)
            ->assertJsonPath('order.status', 'paid')
            ->assertJsonPath('order.total_cents', 179800);

        $this->variant->refresh();
        $this->assertSame(1, $this->variant->stock_on_hand, 'Paid stock should leave the shelf.');
        $this->assertSame(0, $this->variant->stock_reserved, 'And should not still be reserved.');

        $order = Order::query()->firstOrFail();
        $this->assertSame(InventoryState::Committed, $order->inventory_state);
        $this->assertNotNull($order->paid_at);
        $this->assertNotNull($order->payment_intent_id);
    }

    /**
     * The rail this market actually runs on. The customer leaves with a code
     * and no money has moved, so the stock is held rather than taken — for up
     * to 72 hours.
     */
    #[Test]
    public function an_offline_reference_holds_the_stock_without_taking_it(): void
    {
        $token = $this->fillCart(2);

        $response = $this->storefront('POST', '/checkout', $this->details('reference'), token: $token);

        $response->assertStatus(201)
            ->assertJsonPath('order.status', 'awaiting_payment')
            ->assertJsonPath('customer_action.type', 'reference');

        $this->assertNotEmpty($response->json('customer_action.reference'));

        $this->variant->refresh();
        $this->assertSame(3, $this->variant->stock_on_hand, 'Nothing has been paid for, so nothing leaves the shelf.');
        $this->assertSame(2, $this->variant->stock_reserved, 'But it is held against the order.');
        $this->assertSame(1, $this->variant->availableStock());
    }

    #[Test]
    public function paying_the_reference_days_later_commits_the_stock(): void
    {
        $this->storefront('POST', '/checkout', $this->details('reference'), token: $this->fillCart(2))
            ->assertStatus(201);

        $this->settle(PaymentStatus::Succeeded);

        $order = Order::query()->firstOrFail();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame(InventoryState::Committed, $order->inventory_state);

        $this->variant->refresh();
        $this->assertSame(1, $this->variant->stock_on_hand);
        $this->assertSame(0, $this->variant->stock_reserved);
    }

    /**
     * The sweep that stops a catalogue selling out to people who never paid.
     */
    #[Test]
    public function a_reference_nobody_pays_gives_the_stock_back(): void
    {
        $this->storefront('POST', '/checkout', $this->details('reference'), token: $this->fillCart(2))
            ->assertStatus(201);

        $this->travel(4)->days();

        $this->assertSame(1, app(PaymentService::class)->expireOverdue());

        $order = Order::query()->firstOrFail();
        $this->assertSame(OrderStatus::Expired, $order->status);
        $this->assertSame(InventoryState::Released, $order->inventory_state);

        $this->variant->refresh();
        $this->assertSame(3, $this->variant->stock_on_hand);
        $this->assertSame(0, $this->variant->stock_reserved, 'The hold is over, so the shirts are back for sale.');
    }

    /**
     * Providers retry callbacks they are not sure we received, and an hourly
     * expiry sweep can race a payment made two minutes ago. Applying either
     * twice decrements the catalogue twice, silently and permanently.
     */
    #[Test]
    public function a_settlement_applied_twice_only_moves_the_stock_once(): void
    {
        $this->storefront('POST', '/checkout', $this->details('reference'), token: $this->fillCart(2))
            ->assertStatus(201);

        $this->settle(PaymentStatus::Succeeded, eventId: 'evt-1');
        $this->settle(PaymentStatus::Succeeded, eventId: 'evt-2');

        $this->variant->refresh();
        $this->assertSame(1, $this->variant->stock_on_hand, 'Two callbacks, one sale.');
        $this->assertSame(0, $this->variant->stock_reserved);
    }

    /**
     * The reservation is claimed in the WHERE clause, not decided in PHP. Two
     * shoppers both read "1 available" at the same instant; only one of them
     * may reserve it.
     */
    #[Test]
    public function two_shoppers_cannot_both_buy_the_last_one(): void
    {
        $this->variant->update(['stock_on_hand' => 1]);

        $first = $this->fillCart(1);
        $second = $this->fillCart(1);

        $this->storefront('POST', '/checkout', $this->details('reference'), token: $first)
            ->assertStatus(201);

        $this->storefront('POST', '/checkout', $this->details('reference'), token: $second)
            // 409, not 422: the request was valid when it was made and the
            // shopper did nothing wrong.
            ->assertStatus(409)
            ->assertJsonPath('out_of_stock.sku', 'LIN-M')
            ->assertJsonPath('out_of_stock.available', 0);

        $this->assertSame(1, Order::query()->count(), 'The refused checkout must not leave an order behind.');
        $this->assertSame(1, $this->variant->refresh()->stock_reserved);
    }

    /**
     * A decline is not a special case to be handled in the checkout — it is a
     * terminal payment state like any other, so it travels the same
     * PaymentSettled path a Fawry expiry does and releases the stock there.
     */
    #[Test]
    public function a_declined_card_gives_the_stock_straight_back(): void
    {
        config([
            'kavo.payments.rails.card' => 'declining',
            'kavo.payments.gateways.declining' => DecliningGateway::class,
        ]);

        $response = $this->storefront('POST', '/checkout', $this->details('card'), token: $this->fillCart(2));

        $response->assertStatus(201)
            ->assertJsonPath('order.status', 'cancelled')
            ->assertJsonPath('customer_action', null);

        $order = Order::query()->firstOrFail();
        $this->assertSame(InventoryState::Released, $order->inventory_state);

        $this->variant->refresh();
        $this->assertSame(3, $this->variant->stock_on_hand);
        $this->assertSame(0, $this->variant->stock_reserved, 'A declined card must not hold stock.');
    }

    /**
     * The race the inventory_state claim exists for, and the one the payment
     * module's own "a settled payment stays settled" guard does not cover: a
     * customer pays a reference at a kiosk minutes after the merchant
     * cancelled the order it was issued for.
     *
     * The stock is already back on the shelf and may have been sold again, so
     * it must not be taken a second time — but somebody has been charged, and
     * that has to be loud.
     */
    #[Test]
    public function a_payment_arriving_after_a_cancellation_does_not_take_the_stock_twice(): void
    {
        $this->storefront('POST', '/checkout', $this->details('reference'), token: $this->fillCart(2))
            ->assertStatus(201);

        $order = Order::query()->firstOrFail();
        $this->merchant('POST', '/api/orders/'.$order->getKey().'/cancel')->assertOk();

        Log::shouldReceive('critical')
            ->once()
            ->withArgs(fn (string $message): bool => str_contains($message, 'already closed'));

        $this->settle(PaymentStatus::Succeeded);

        $this->variant->refresh();
        $this->assertSame(3, $this->variant->stock_on_hand, 'Stock that went back on the shelf stays there.');
        $this->assertSame(0, $this->variant->stock_reserved);

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(InventoryState::Released, $order->inventory_state);
    }

    /**
     * The claim itself, driven directly.
     *
     * Every route into OrderSettlement checks the order's status before
     * calling it, so no request can reach a second commit — which is exactly
     * why the guard underneath needs its own test rather than being assumed
     * from the outside. Two settlements crossing a cancellation is a race
     * between a provider callback and a merchant clicking a button, and it is
     * not reproducible from a single request.
     */
    #[Test]
    public function the_stock_of_one_order_can_only_ever_be_moved_once(): void
    {
        WebhookSubscription::factory()->create(['event_types' => ['order.paid'], 'is_active' => true]);

        $this->storefront('POST', '/checkout', $this->details('reference'), token: $this->fillCart(2))
            ->assertStatus(201);

        $settlement = app(OrderSettlement::class);
        $order = Order::query()->firstOrFail();

        $settlement->markPaid($order);

        $this->variant->refresh();
        $this->assertSame(1, $this->variant->stock_on_hand);
        $this->assertSame(0, $this->variant->stock_reserved);

        // The same settlement again, and then a cancellation crossing it.
        $settlement->markPaid($order->fresh());
        $settlement->close($order->fresh(), OrderStatus::Cancelled);

        $this->variant->refresh();
        $this->assertSame(1, $this->variant->stock_on_hand, 'The shirts left the shelf once, not twice.');
        $this->assertSame(0, $this->variant->stock_reserved, 'And were not handed back on top of that.');

        // The claim is also what stops the merchant's own systems being told
        // twice that the same order was paid.
        $this->assertSame(1, WebhookDelivery::query()->where('event_type', 'order.paid')->count());
    }

    // ------------------------------------------------------------ the order

    /**
     * An order that changes retroactively is not a record, it is a liability.
     */
    #[Test]
    public function an_order_remembers_what_was_agreed_even_after_the_catalogue_moves_on(): void
    {
        $this->storefront('POST', '/checkout', $this->details('card'), token: $this->fillCart(1))
            ->assertStatus(201);

        $this->product->update(['name' => 'Linen Shirt (Relaxed)']);
        $this->variant->update(['price_cents' => 49900, 'sku' => 'LIN-M-V2']);

        $item = Order::query()->firstOrFail()->items()->firstOrFail();

        $this->assertSame('Linen Shirt', $item->product_name);
        $this->assertSame('LIN-M', $item->variant_sku);
        $this->assertSame(89900, $item->unit_price_cents);
    }

    /** Deleting a product changes the catalogue, not the history of sales. */
    #[Test]
    public function deleting_a_product_does_not_erase_what_was_sold(): void
    {
        $this->storefront('POST', '/checkout', $this->details('card'), token: $this->fillCart(1))
            ->assertStatus(201);

        $this->variant->delete();

        $item = Order::query()->firstOrFail()->items()->firstOrFail();

        $this->assertNull($item->product_variant_id);
        $this->assertSame('Linen Shirt', $item->product_name);
        $this->assertSame(89900, $item->unit_price_cents);
    }

    #[Test]
    public function the_order_status_page_needs_the_email_it_was_placed_with(): void
    {
        $this->storefront('POST', '/checkout', $this->details('reference'), token: $this->fillCart(1))
            ->assertStatus(201);

        $number = Order::query()->firstOrFail()->number;

        $this->storefront('GET', '/orders/'.$number.'?email=nadia@example.test')
            ->assertOk()
            ->assertJsonPath('order.number', $number)
            ->assertJsonPath('customer_action.type', 'reference');

        $this->storefront('GET', '/orders/'.$number.'?email=someone@else.test')
            ->assertStatus(404);
    }

    /**
     * An intent keeps its payment_reference forever — it is the history of
     * what was issued. Rendering the order page off that column alone tells
     * somebody who has already paid to go and pay the same code again at a
     * kiosk, which is a second charge for one order.
     */
    #[Test]
    public function a_paid_order_stops_telling_the_customer_to_go_and_pay(): void
    {
        $this->storefront('POST', '/checkout', $this->details('reference'), token: $this->fillCart(1))
            ->assertStatus(201);

        $number = Order::query()->firstOrFail()->number;

        $this->storefront('GET', '/orders/'.$number.'?email=nadia@example.test')
            ->assertOk()
            ->assertJsonPath('customer_action.type', 'reference');

        $this->settle(PaymentStatus::Succeeded);

        $this->storefront('GET', '/orders/'.$number.'?email=nadia@example.test')
            ->assertOk()
            ->assertJsonPath('order.status', 'paid')
            ->assertJsonPath('customer_action', null);
    }

    /** And neither does one whose window closed with the stock released. */
    #[Test]
    public function an_expired_reference_stops_being_payable(): void
    {
        $this->storefront('POST', '/checkout', $this->details('reference'), token: $this->fillCart(1))
            ->assertStatus(201);

        $number = Order::query()->firstOrFail()->number;

        $this->travel(4)->days();

        $this->storefront('GET', '/orders/'.$number.'?email=nadia@example.test')
            ->assertOk()
            // Before the sweep has even run: the window is what closed it,
            // not the job that notices.
            ->assertJsonPath('customer_action', null);
    }

    // --------------------------------------------------------------- quotas

    /**
     * `orders` is a *flow* metric: it counts orders placed in a period and is
     * never given back, because cancelling an order does not un-place it.
     * That is the opposite of `products`, where deleting returns the slot.
     */
    #[Test]
    public function cancelling_an_order_does_not_return_the_order_allowance(): void
    {
        $this->capOrdersAt(1);

        $this->storefront('POST', '/checkout', $this->details('reference'), token: $this->fillCart(1))
            ->assertStatus(201);

        $order = Order::query()->firstOrFail();

        $this->merchant('POST', '/api/orders/'.$order->getKey().'/cancel')->assertOk();

        // The stock came back...
        $this->assertSame(0, $this->variant->refresh()->stock_reserved);

        // ...but the order still happened, so the allowance is still spent.
        $this->storefront('POST', '/checkout', $this->details('reference'), token: $this->fillCart(1))
            ->assertStatus(402);
    }

    /** A refused checkout must not leave stock held for an order nobody has. */
    #[Test]
    public function a_checkout_refused_on_quota_leaves_no_reservation_behind(): void
    {
        $this->capOrdersAt(0);

        $this->storefront('POST', '/checkout', $this->details('reference'), token: $this->fillCart(2))
            // 402, not 403: they may do this on a larger plan.
            ->assertStatus(402);

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, $this->variant->refresh()->stock_reserved);
    }

    // ------------------------------------------------------------- merchant

    #[Test]
    public function a_merchant_cancelling_an_unpaid_order_puts_the_stock_back(): void
    {
        $this->storefront('POST', '/checkout', $this->details('reference'), token: $this->fillCart(2))
            ->assertStatus(201);

        $order = Order::query()->firstOrFail();

        $this->merchant('POST', '/api/orders/'.$order->getKey().'/cancel')
            ->assertOk()
            ->assertJsonPath('order.status', 'cancelled');

        $this->variant->refresh();
        $this->assertSame(3, $this->variant->stock_on_hand);
        $this->assertSame(0, $this->variant->stock_reserved);
    }

    /** A paid order is a refund, which moves money, not a cancellation. */
    #[Test]
    public function a_paid_order_cannot_simply_be_cancelled(): void
    {
        $this->storefront('POST', '/checkout', $this->details('card'), token: $this->fillCart(1))
            ->assertStatus(201);

        $this->merchant('POST', '/api/orders/'.Order::query()->firstOrFail()->getKey().'/cancel')
            ->assertStatus(422);

        $this->assertSame(2, $this->variant->refresh()->stock_on_hand, 'A refused cancellation must not restock.');
    }

    #[Test]
    public function the_merchant_sees_their_orders_newest_first(): void
    {
        $this->storefront('POST', '/checkout', $this->details('reference'), token: $this->fillCart(1))->assertStatus(201);
        $this->storefront('POST', '/checkout', $this->details('reference'), token: $this->fillCart(1))->assertStatus(201);

        $this->merchant('GET', '/api/orders')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.number', Order::query()->max('number'));
    }

    // ------------------------------------------------------------- webhooks

    /**
     * The first production caller of the outbound webhook path. It was built,
     * tested and then never invoked by anything — which is the same as not
     * having it.
     */
    #[Test]
    public function a_merchant_subscribed_to_orders_is_told_about_them(): void
    {
        WebhookSubscription::factory()->create([
            'event_types' => ['order.placed', 'order.paid'],
            'is_active' => true,
        ]);

        $this->storefront('POST', '/checkout', $this->details('card'), token: $this->fillCart(1))
            ->assertStatus(201);

        $this->assertDatabaseHas('webhook_deliveries', ['event_type' => 'order.placed']);
        $this->assertDatabaseHas('webhook_deliveries', ['event_type' => 'order.paid']);
    }

    // -------------------------------------------------------------- helpers

    /** Add the variant to a fresh cart and return the cart's token. */
    private function fillCart(int $quantity): string
    {
        return (string) $this->storefront('POST', '/cart/items', [
            'variant_id' => $this->variant->getKey(),
            'quantity' => $quantity,
        ])->assertStatus(201)->json('cart.token');
    }

    /** @return array<string, mixed> */
    private function details(string $rail): array
    {
        return [
            'customer_name' => 'Nadia Hassan',
            'customer_email' => 'nadia@example.test',
            'customer_phone' => '+201000000001',
            'rail' => $rail,
            'shipping_address' => ['line1' => '12 Road 9, Maadi', 'city' => 'Cairo'],
        ];
    }

    /**
     * A shopper's request, addressed the way a shopper's browser addresses it.
     * The storefront is anonymous and resolves its tenant from the hostname.
     */
    private function storefront(string $method, string $path, array $body = [], ?string $token = null): TestResponse
    {
        $headers = $token === null ? [] : ['X-Cart-Token' => $token];

        return $this->withHeaders($headers)->json(
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

    /** Deliver a provider callback for the order's outstanding payment. */
    private function settle(PaymentStatus $status, string $eventId = 'evt-settle'): void
    {
        $intent = Order::query()->firstOrFail()->paymentIntent;

        $notice = app(PaymentGatewayManager::class)->gateway('fake')->parseSettlement([
            'reference' => $intent->public_reference,
            'status' => $status->value,
            'amount_cents' => $intent->amount_cents,
            'event_id' => $eventId.'-'.Str::random(6),
        ]);

        app(PaymentService::class)->settle($notice, 'fake');
    }

    private function capOrdersAt(int $limit): void
    {
        $this->tenant->update(['plan_id' => $this->planAllowingOrders($limit)->getKey()]);
        app(Entitlements::class)->flush($this->tenant);
    }

    /** @param  int|null  $limit  null grants unlimited orders */
    private function planAllowingOrders(?int $limit): Plan
    {
        $plan = Plan::factory()->create();

        PlanFeature::query()->create([
            'plan_id' => $plan->getKey(),
            'feature_key' => 'orders',
            'limit_value' => $limit,
            'overage_behavior' => 'block',
        ]);

        return $plan;
    }
}
