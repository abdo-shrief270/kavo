<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Orders\Listeners;

use App\Modules\Commerce\Orders\Models\Order;
use App\Modules\Commerce\Orders\Services\OrderSettlement;
use App\Shared\Events\PaymentSettled;
use Illuminate\Support\Facades\Log;

/**
 * The seam Phase 0 built PaymentSettled for.
 *
 * Billing knows a payment reached a terminal state. It does not know orders
 * exist, and must not: the platform has to serve verticals that have no orders
 * at all. So the payment module announces what happened and this listener,
 * which lives in the vertical, decides what it means for stock.
 *
 * It runs on both paths without knowing which it is on. A card settles inside
 * the checkout request; a Fawry reference settles from a provider callback
 * three days later, in a queued job. Both arrive here with the tenant already
 * bound — checkout because it is in a tenant's request, the callback because
 * ApplyGatewaySettlement binds the tenant it resolved from the intent.
 */
final readonly class SettleOrder
{
    public function __construct(private OrderSettlement $settlement) {}

    public function handle(PaymentSettled $event): void
    {
        $intent = $event->intent;

        // Most payments in this platform are not orders: subscriptions and
        // invoices settle through the same rails.
        if (! array_key_exists('order_id', (array) $intent->metadata)) {
            return;
        }

        $order = Order::forIntent($intent);

        if ($order === null) {
            // The intent says it belongs to an order and no order answers.
            // Either the tenant is not bound — in which case row-level
            // security has correctly returned nothing and the stock this
            // order holds will never be released — or the row is gone. Both
            // need a human; neither is safe to shrug at.
            Log::critical('Settled payment names an order that cannot be found', [
                'payment_intent_id' => $intent->getKey(),
                'order_id' => $intent->metadata['order_id'] ?? null,
                'tenant_id' => $intent->tenant_id,
                'to' => $event->to->value,
            ]);

            return;
        }

        $this->settlement->apply($order, $event->to);
    }
}
