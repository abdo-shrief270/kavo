<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Orders\Services;

use App\Modules\Commerce\Catalogue\Services\Inventory;
use App\Modules\Commerce\Orders\Enums\InventoryState;
use App\Modules\Commerce\Orders\Enums\OrderStatus;
use App\Modules\Commerce\Orders\Models\Order;
use App\Shared\Contracts\OutboundEvents;
use App\Shared\Enums\PaymentStatus;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;

/**
 * The one place an order's stock ever moves after checkout.
 *
 * Settlements are not delivered once. A provider retries a callback it is not
 * sure we received; an hourly expiry sweep can run against an intent a
 * customer paid two minutes ago; a merchant can cancel an order in the same
 * second it settles. Any of those applied twice is either stock decremented
 * twice or a reservation returned twice — both of which corrupt the catalogue
 * silently and permanently.
 *
 * So the transition is claimed before it is acted on, with a conditional
 * UPDATE on the order's inventory_state. Exactly one caller wins the claim;
 * everyone else gets false and does nothing. The claim is what makes this
 * idempotent, not the caller remembering to check first.
 */
final readonly class OrderSettlement
{
    public function __construct(
        private Inventory $inventory,
        private OutboundEvents $events,
        private TenantContext $tenants,
    ) {}

    /**
     * Apply a terminal payment outcome to the order behind it.
     *
     * Only ever called for final payment states — PaymentSettled does not fire
     * for anything else — so there is no case here for "still waiting".
     */
    public function apply(Order $order, PaymentStatus $payment): void
    {
        $status = Order::statusForPayment($payment);

        // A late callback cannot reopen a closed order. The one exception is a
        // refund, which legitimately follows a paid order.
        if ($order->status->isFinal() && $status !== OrderStatus::Refunded) {
            if ($status === OrderStatus::Paid) {
                // Money arrived for an order that was cancelled or expired,
                // which is reachable: a customer can pay a Fawry reference at
                // a kiosk minutes after the merchant cancelled the order it
                // was issued for. The stock went back to the shelf and may
                // already be sold to somebody else, so it is not taken again
                // here — but somebody has been charged for goods this shop is
                // no longer holding, and that needs a person, not a retry.
                Log::critical('Payment succeeded against an order that is already closed', [
                    'order_id' => $order->getKey(),
                    'order_number' => $order->number,
                    'order_status' => $order->status->value,
                    'inventory_state' => $order->inventory_state->value,
                ]);
            }

            return;
        }

        match ($status) {
            OrderStatus::Paid => $this->markPaid($order),
            OrderStatus::Refunded => $this->markRefunded($order),
            default => $this->close($order, $status),
        };
    }

    /** Paid: the goods leave the shelf and the reservation ends. */
    public function markPaid(Order $order): void
    {
        $committed = $this->claim($order, InventoryState::Committed);

        if ($committed) {
            $this->moveStock($order, commit: true);
        }

        $order->forceFill([
            'status' => OrderStatus::Paid,
            'paid_at' => $order->paid_at ?? now(),
            'closed_at' => null,
        ])->save();

        // Only announced by whoever won the claim, so a retried callback does
        // not fire a second order.paid at the merchant's own systems.
        if ($committed) {
            $this->announce($order, 'order.paid');
        }
    }

    /**
     * Expired, failed or cancelled: the stock goes back to the catalogue.
     *
     * @param  OrderStatus  $status  which of those it was
     */
    public function close(Order $order, OrderStatus $status): void
    {
        $released = $this->claim($order, InventoryState::Released);

        if ($released) {
            $this->moveStock($order, commit: false);
        }

        $order->forceFill([
            'status' => $status,
            'closed_at' => $order->closed_at ?? now(),
        ])->save();

        if ($released) {
            $this->announce($order, 'order.cancelled');
        }
    }

    /**
     * Refunded. Stock is deliberately not returned.
     *
     * By the time a refund happens the goods have usually shipped, and a
     * platform that silently put them back on the shelf would oversell the
     * merchant's next customer. Restocking a return is a warehouse decision,
     * so it belongs on the merchant's side of the fulfilment flow — not in a
     * webhook handler.
     */
    private function markRefunded(Order $order): void
    {
        $order->forceFill([
            'status' => OrderStatus::Refunded,
            'closed_at' => $order->closed_at ?? now(),
        ])->save();

        $this->announce($order, 'order.refunded');
    }

    /**
     * Win the right to move this order's stock, exactly once.
     *
     * A conditional UPDATE rather than a read-then-write: two settlements
     * arriving together both read `reserved`, and both would proceed.
     */
    private function claim(Order $order, InventoryState $to): bool
    {
        $claimed = Order::query()
            ->whereKey($order->getKey())
            ->where('inventory_state', InventoryState::Reserved->value)
            ->update(['inventory_state' => $to->value, 'updated_at' => now()]);

        if ($claimed === 1) {
            $order->setAttribute('inventory_state', $to);
        }

        return $claimed === 1;
    }

    private function moveStock(Order $order, bool $commit): void
    {
        foreach ($order->items()->get() as $item) {
            // The merchant deleted the product after the order was placed. The
            // variant row is gone and took its counters with it; the snapshot
            // on the order line is what survives, which is the point of it.
            if ($item->product_variant_id === null) {
                continue;
            }

            $moved = $commit
                ? $this->inventory->commit($item->product_variant_id, $item->quantity)
                : $this->inventory->release($item->product_variant_id, $item->quantity);

            if (! $moved) {
                // The reservation this order was holding is not there any
                // more. Nothing to do about it here, but a catalogue whose
                // counters have drifted needs a human, not a retry.
                Log::critical('Order stock movement found no reservation', [
                    'order_id' => $order->getKey(),
                    'order_item_id' => $item->getKey(),
                    'variant_id' => $item->product_variant_id,
                    'quantity' => $item->quantity,
                    'movement' => $commit ? 'commit' : 'release',
                ]);
            }
        }
    }

    /** Tell the merchant's own systems, if they asked to be told. */
    private function announce(Order $order, string $event): void
    {
        $tenant = $this->tenants->get();

        if ($tenant === null) {
            return;
        }

        $this->events->publish($tenant, $event, [
            'order' => $order->loadMissing('items')->summary(),
            'customer' => [
                'name' => $order->customer_name,
                'email' => $order->customer_email,
                'phone' => $order->customer_phone,
            ],
        ]);
    }
}
