<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Orders\Services;

use App\Modules\Commerce\Catalogue\Enums\ProductStatus;
use App\Modules\Commerce\Catalogue\Models\ProductVariant;
use App\Modules\Commerce\Catalogue\Services\Inventory;
use App\Modules\Commerce\Orders\Enums\InventoryState;
use App\Modules\Commerce\Orders\Enums\OrderStatus;
use App\Modules\Commerce\Orders\Exceptions\OutOfStock;
use App\Modules\Commerce\Orders\Models\Cart;
use App\Modules\Commerce\Orders\Models\Order;
use App\Modules\Commerce\Orders\Models\OrderItem;
use App\Modules\Platform\Billing\Models\PaymentIntent;
use App\Modules\Platform\Billing\Payments\Money;
use App\Modules\Platform\Billing\Payments\PaymentRequest;
use App\Modules\Platform\Billing\Payments\PaymentService;
use App\Shared\Contracts\Entitlements;
use App\Shared\Contracts\OutboundEvents;
use App\Shared\Exceptions\QuotaExceeded;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Turning a cart into an order and a payment.
 *
 * The sequencing here is the whole design, and it is chosen so that no failure
 * leaves the system in a state a human has to repair.
 *
 *   1. Reserve the stock. Done first because it is the only step that can fail
 *      for a reason the shopper can act on, and the only one that is cheap to
 *      undo.
 *   2. Meter the order. `orders` is a *flow* metric: it counts orders placed
 *      in a period and is never given back, because cancelling an order does
 *      not un-place it. That makes charging it the one irreversible step, so
 *      it goes after everything that might still refuse the checkout.
 *   3. Write the order, with every price and name copied onto it.
 *   4. Only then call the gateway — outside the transaction, because an
 *      external HTTP call inside one holds row locks for as long as the
 *      provider takes to answer, and this market's providers sometimes take
 *      seconds.
 *
 * Steps 1-3 share a transaction, so a failure anywhere in them rolls the
 * reservations back rather than relying on compensating writes that can
 * themselves fail. The one seam that cannot be transactional is the meter,
 * which lives in Redis: if the order write fails after it was charged, the
 * tenant's order count is one high for the period. That is the least harmful
 * place to put the inaccuracy, and it is stated here rather than discovered.
 */
final readonly class Checkout
{
    public function __construct(
        private Inventory $inventory,
        private Entitlements $entitlements,
        private PaymentService $payments,
        private OrderSettlement $settlement,
        private OutboundEvents $events,
        private TenantContext $tenants,
    ) {}

    /**
     * @param  ?Cart  $cart  null when the shopper presented no token, or one
     *                       that has expired — the same dead end as an empty
     *                       basket, and answered with the same message
     * @return array{order: Order, payment: ?PaymentIntent}
     *
     * @throws OutOfStock|QuotaExceeded|ValidationException
     */
    public function place(?Cart $cart, CheckoutDetails $details): array
    {
        $tenant = $this->tenants->getOrFail('placing an order');
        $lines = $this->sellableLines($cart);

        // Not null past sellableLines(), which refuses an absent basket.
        assert($cart !== null);

        $order = DB::transaction(function () use ($tenant, $cart, $lines, $details): Order {
            foreach ($lines as $line) {
                if (! $this->inventory->reserve($line['variant']->getKey(), $line['quantity'])) {
                    throw new OutOfStock(
                        sku: $line['variant']->sku,
                        productName: $line['variant']->product->name,
                        requested: $line['quantity'],
                        // Re-read: the number that mattered is the one at the
                        // moment the reservation was refused, not the one the
                        // page was rendered with.
                        available: (int) $line['variant']->fresh()?->availableStock(),
                    );
                }
            }

            $metered = $this->entitlements->consume($tenant, 'orders');

            if ($metered->blocked()) {
                // Rolls back the reservations above with it. The shopper sees
                // a shop that is not taking orders, which is the truth.
                throw new QuotaExceeded($metered);
            }

            $order = $this->writeOrder($lines, $details);

            // The cart has become the order. Keeping it would leave the
            // shopper a second way to buy the same reservation; the order is
            // the durable record, and the thing to retry payment against.
            $cart->delete();

            return $order;
        });

        $this->events->publish($tenant, 'order.placed', ['order' => $order->loadMissing('items')->summary()]);

        return ['order' => $order, 'payment' => $this->collect($order, $details)];
    }

    /**
     * Hand the order to a gateway.
     *
     * Gateways translate their own failures into a failed PaymentResult rather
     * than throwing, which raises PaymentSettled and releases the stock
     * through the ordinary path. The catch is for everything else — the
     * intent row failing to write, a misconfigured rail — where nothing has
     * released the reservation and nothing else will.
     */
    private function collect(Order $order, CheckoutDetails $details): ?PaymentIntent
    {
        try {
            $intent = $this->payments->charge(new PaymentRequest(
                reference: $order->reference(),
                // Platform-generated and globally unique. It is the only
                // identifier the gateway is given, so a callback resolves to
                // exactly one tenant — the merchant's own order number cannot,
                // because two merchants may both be on their 1001st order.
                publicReference: 'kv_'.Str::lower((string) Str::ulid()),
                amount: new Money($order->total_cents, $order->currency),
                rail: $details->rail,
                customerName: $order->customer_name,
                customerEmail: $order->customer_email,
                customerPhone: $order->customer_phone,
                // How a settlement finds its way back to this order. Written
                // at intent creation because a card rail settles inside this
                // very call, before the column below could be set.
                metadata: ['order_id' => $order->getKey(), 'order_number' => $order->number],
                returnUrl: $details->returnUrl,
            ));
        } catch (Throwable $e) {
            Log::error('Checkout could not reach a gateway', [
                'order_id' => $order->getKey(),
                'rail' => $details->rail->value,
                'exception' => $e->getMessage(),
            ]);

            // Nobody else will free this stock: there is no intent for the
            // expiry sweep to find.
            $this->settlement->close($order, OrderStatus::Cancelled);

            return null;
        }

        // A synchronous rail may already have settled this order through the
        // PaymentSettled listener while charge() was running, so re-read
        // before deciding anything about its status.
        $order->refresh();

        $order->forceFill([
            'payment_intent_id' => $intent->getKey(),
            'status' => $order->status === OrderStatus::Pending
                ? Order::statusForPayment($intent->status)
                : $order->status,
        ])->save();

        return $intent;
    }

    /**
     * The cart's lines, re-read from the catalogue under the checkout's own
     * eyes.
     *
     * Re-queried rather than trusted from the cart because a cart can be days
     * old: the product may have been unpublished, the variant deactivated or
     * the price changed since it was filled. The price charged is the one
     * standing now, which is also why carts store no prices at all.
     *
     * @return list<array{variant: ProductVariant, quantity: int}>
     */
    private function sellableLines(?Cart $cart): array
    {
        $cart?->loadMissing('items.variant.product');

        if ($cart === null || $cart->items->isEmpty()) {
            throw ValidationException::withMessages(['cart' => 'Your cart is empty.']);
        }

        $variants = ProductVariant::query()
            ->with('product')
            ->where('is_active', true)
            ->whereHas('product', fn ($query) => $query->where('status', ProductStatus::Active))
            ->whereIn('id', $cart->items->pluck('product_variant_id'))
            ->get()
            ->keyBy('id');

        $lines = [];
        $currencies = [];

        foreach ($cart->items as $item) {
            $variant = $variants->get($item->product_variant_id);

            if ($variant === null) {
                throw ValidationException::withMessages([
                    'cart' => 'An item in your cart is no longer for sale. Please review your basket.',
                ]);
            }

            $currencies[$variant->product->currency] = true;
            $lines[] = ['variant' => $variant, 'quantity' => $item->quantity];
        }

        // One order, one amount, one currency — a gateway is charged a single
        // total and there is nowhere to put a second one.
        if (count($currencies) > 1) {
            throw ValidationException::withMessages([
                'cart' => 'Your basket mixes currencies and cannot be checked out together.',
            ]);
        }

        return $lines;
    }

    /**
     * @param  list<array{variant: ProductVariant, quantity: int}>  $lines
     */
    private function writeOrder(array $lines, CheckoutDetails $details): Order
    {
        $subtotal = 0;

        foreach ($lines as $line) {
            $subtotal += $line['variant']->price_cents * $line['quantity'];
        }

        $currency = $lines[0]['variant']->product->currency;

        $order = $this->createWithNumber([
            'status' => OrderStatus::Pending,
            'inventory_state' => InventoryState::Reserved,
            'customer_name' => $details->customerName,
            'customer_email' => $details->customerEmail,
            'customer_phone' => $details->customerPhone,
            'shipping_address' => $details->shippingAddress,
            'subtotal_cents' => $subtotal,
            'shipping_cents' => $details->shippingCents,
            'total_cents' => $subtotal + $details->shippingCents,
            'currency' => $currency,
            'placed_at' => now(),
        ]);

        foreach ($lines as $line) {
            $variant = $line['variant'];

            OrderItem::create([
                'order_id' => $order->getKey(),
                'product_variant_id' => $variant->getKey(),
                // Copied, not joined. What the customer agreed to buy does not
                // change because the merchant renamed or re-priced it after.
                'product_name' => $variant->product->name,
                'variant_sku' => $variant->sku,
                'options' => $variant->options,
                'unit_price_cents' => $variant->price_cents,
                'quantity' => $line['quantity'],
                'total_cents' => $variant->price_cents * $line['quantity'],
            ]);
        }

        return $order->load('items');
    }

    /**
     * Allocate the next order number for this tenant.
     *
     * max()+1 under the tenant's own row-level security, so the sequence is
     * per shop and reveals nothing about the platform's total volume. Two
     * simultaneous checkouts can compute the same number; the unique index on
     * (tenant_id, number) rejects the loser and it tries again. Retrying a
     * collision is cheaper and simpler than a per-tenant sequence, and unlike
     * a sequence it cannot drift from the rows it numbers.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createWithNumber(array $attributes): Order
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $next = ((int) Order::query()->max('number') ?: Order::FIRST_NUMBER) + 1;

            try {
                // A savepoint, because this runs inside the checkout's
                // transaction and a unique violation aborts a Postgres
                // transaction outright — without one, the retry below would
                // find every later statement refused.
                return DB::transaction(fn (): Order => Order::create([...$attributes, 'number' => $next]));
            } catch (QueryException $e) {
                // 23505: another checkout took this number first.
                if ($e->getCode() !== '23505' || $attempt === 5) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('Could not allocate an order number.');
    }
}
