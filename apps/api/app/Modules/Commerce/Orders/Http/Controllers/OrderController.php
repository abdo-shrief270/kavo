<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Orders\Http\Controllers;

use App\Modules\Commerce\Orders\Enums\OrderStatus;
use App\Modules\Commerce\Orders\Models\Order;
use App\Modules\Commerce\Orders\Services\OrderSettlement;
use App\Modules\Platform\Audit\Services\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The merchant's order book. Tenant-scoped by middleware and by RLS. */
final class OrderController
{
    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->with('items')
            ->when(
                $request->string('status')->toString(),
                fn ($query, string $status) => $query->where('status', $status),
            )
            ->when(
                $request->string('q')->toString(),
                fn ($query, string $term) => $query->where(function ($q) use ($term): void {
                    $q->where('customer_name', 'ilike', '%'.$term.'%')
                        ->orWhere('customer_email', 'ilike', '%'.$term.'%');

                    // Only when it could be one. `number` is a bigint, and
                    // Postgres answers `number = 'nadia'` with an error rather
                    // than no rows — so searching by name would 500.
                    if (ctype_digit($term)) {
                        $q->orWhere('number', (int) $term);
                    }
                }),
            )
            ->latest('number')
            ->paginate(25);

        return response()->json($orders);
    }

    public function show(Order $order): JsonResponse
    {
        return response()->json([
            'order' => $order->load('items'),
            'customer' => [
                'name' => $order->customer_name,
                'email' => $order->customer_email,
                'phone' => $order->customer_phone,
                'shipping_address' => $order->shipping_address,
            ],
            'payment' => $order->paymentIntent,
            'customer_action' => $order->paymentIntent?->customerAction(),
        ]);
    }

    /**
     * Cancel an unpaid order and put its stock back.
     *
     * Only while it is still open. A paid order is a refund, which moves money
     * and is the payment module's business — and unlike a cancellation it must
     * not restock automatically, because by then the goods have usually
     * shipped.
     */
    public function cancel(Request $request, Order $order, OrderSettlement $settlement, AuditRecorder $audit): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in([OrderStatus::Cancelled->value, OrderStatus::Expired->value])],
        ]);

        if ($order->status->isFinal()) {
            return response()->json([
                'message' => 'This order is already closed and cannot be cancelled.',
            ], 422);
        }

        $settlement->close($order, OrderStatus::from($validated['status'] ?? OrderStatus::Cancelled->value));

        $audit->record('order.cancelled', $order, null, ['number' => $order->number]);

        return response()->json(['order' => $order->refresh()->load('items')]);
    }
}
