<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Orders\Http\Controllers\Storefront;

use App\Modules\Commerce\Orders\Models\Order;
use App\Modules\Commerce\Orders\Services\CartManager;
use App\Modules\Commerce\Orders\Services\Checkout;
use App\Modules\Commerce\Orders\Services\CheckoutDetails;
use App\Modules\Platform\Billing\Payments\PaymentGatewayManager;
use App\Modules\Platform\Billing\Payments\PaymentRail;
use App\Shared\Contracts\AnalyticsIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Where a basket becomes an order and money is asked for.
 *
 * The response deliberately does not say "paid" or "failed". It says what the
 * customer has to do next, because on the rail most of this market uses the
 * answer is "take this number to an outlet" and the money arrives days later.
 */
final class CheckoutController
{
    /** What this shop can actually be paid through. */
    public function rails(PaymentGatewayManager $gateways): JsonResponse
    {
        return response()->json([
            'rails' => array_map(static fn (PaymentRail $rail): array => [
                'rail' => $rail->value,
                'label' => $rail->label(),
                'offline' => $rail->isOffline(),
            ], $gateways->availableRails()),
        ]);
    }

    public function store(Request $request, Checkout $checkout, CartManager $carts, AnalyticsIngestor $analytics): JsonResponse
    {
        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['required', 'email', 'max:255'],
            // Required on every rail. A reference payment has no other way to
            // reach the payer with their code, and a gateway discovering that
            // at send time fails the checkout instead of the validation.
            'customer_phone' => ['required', 'string', 'max:32'],
            'rail' => ['required', Rule::enum(PaymentRail::class)],
            'shipping_address' => ['sometimes', 'array'],
            'shipping_address.line1' => ['required_with:shipping_address', 'string', 'max:255'],
            'shipping_address.city' => ['required_with:shipping_address', 'string', 'max:120'],
            'shipping_address.governorate' => ['sometimes', 'nullable', 'string', 'max:120'],
            'shipping_address.notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'return_url' => ['sometimes', 'nullable', 'url'],
        ]);

        // existing(), not resolve(): a checkout with no basket is refused, and
        // must not leave a cart row behind for a token nobody was given.
        $cart = $carts->existing($request->header('X-Cart-Token'));

        $placed = $checkout->place($cart, new CheckoutDetails(
            customerName: $validated['customer_name'],
            customerEmail: $validated['customer_email'],
            customerPhone: $validated['customer_phone'],
            rail: PaymentRail::from($validated['rail']),
            shippingAddress: $validated['shipping_address'] ?? [],
            returnUrl: $validated['return_url'] ?? null,
        ));

        $order = $placed['order'];

        $analytics->record('storefront.order_placed', [
            'order_number' => $order->number,
            'rail' => $validated['rail'],
            'total_cents' => $order->total_cents,
        ]);

        return response()->json([
            'order' => $order->summary(),
            // Null when the gateway could not be reached at all; the order is
            // cancelled in that case and says so in its status.
            'customer_action' => $placed['payment']?->customerAction(),
        ], 201);
    }

    /**
     * The order-status page a customer lands on, and refreshes while they wait
     * for an offline payment to be reported.
     *
     * Addressed by order number plus the email it was placed with. Neither is
     * a secret on its own, and together they are only as strong as a magic
     * link — which is the right strength for "show me what I just ordered",
     * and the reason nothing here exposes anything the customer did not
     * already type in.
     */
    public function show(Request $request, string $number): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $order = Order::query()
            ->with('items')
            ->where('number', $number)
            ->whereRaw('lower(customer_email) = ?', [mb_strtolower($validated['email'])])
            ->first();

        if ($order === null) {
            throw new NotFoundHttpException('No such order.');
        }

        return response()->json([
            'order' => $order->summary(),
            'customer_action' => $order->paymentIntent?->customerAction(),
        ]);
    }
}
