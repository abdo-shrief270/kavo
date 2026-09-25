<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Orders\Http\Controllers\Storefront;

use App\Modules\Commerce\Orders\Models\Cart;
use App\Modules\Commerce\Orders\Models\CartItem;
use App\Modules\Commerce\Orders\Services\CartManager;
use App\Shared\Contracts\AnalyticsIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The shopper's basket. Anonymous, like the rest of the storefront.
 *
 * The cart is addressed by an opaque token in a header rather than by a
 * session cookie: the storefront is server-rendered on a different origin from
 * this API, so a cookie would have to be a cross-origin credential on every
 * request. A token the SSR server holds and forwards is both simpler and
 * narrower.
 *
 * Every response carries the token, including the first, so a client that has
 * none gets one by adding something rather than by a separate "create cart"
 * call that does nothing on its own.
 */
final class CartController
{
    public function show(Request $request, CartManager $carts): JsonResponse
    {
        $cart = $carts->resolve($this->token($request));

        return response()->json(['cart' => $carts->present($cart)]);
    }

    public function store(Request $request, CartManager $carts, AnalyticsIngestor $analytics): JsonResponse
    {
        $validated = $request->validate([
            'variant_id' => ['required', 'integer'],
            'quantity' => ['sometimes', 'integer', 'min:1'],
        ]);

        $cart = $carts->add(
            $carts->resolve($this->token($request)),
            $validated['variant_id'],
            $validated['quantity'] ?? 1,
        );

        $analytics->record('storefront.cart_item_added', [
            'variant_id' => $validated['variant_id'],
            'quantity' => $validated['quantity'] ?? 1,
        ]);

        return response()->json(['cart' => $carts->present($cart)], 201);
    }

    public function update(Request $request, string $item, CartManager $carts): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:0'],
        ]);

        $cart = $carts->resolve($this->token($request));

        return response()->json([
            'cart' => $carts->present($carts->setQuantity($cart, $this->lineOf($cart, $item), $validated['quantity'])),
        ]);
    }

    public function destroy(Request $request, string $item, CartManager $carts): JsonResponse
    {
        $cart = $carts->resolve($this->token($request));

        return response()->json([
            'cart' => $carts->present($carts->remove($cart, $this->lineOf($cart, $item))),
        ]);
    }

    /**
     * Resolve a line *through the cart*, never by its id alone.
     *
     * Route-model binding would find any line belonging to the tenant, and
     * this endpoint is anonymous — anyone holding any cart token could then
     * edit anybody else's basket by guessing an id. Row-level security does
     * not help here: both carts belong to the same shop.
     */
    private function lineOf(Cart $cart, string $item): CartItem
    {
        $line = $cart->items()->whereKey($item)->first();

        return $line ?? throw new NotFoundHttpException('No such cart item.');
    }

    private function token(Request $request): ?string
    {
        return $request->header('X-Cart-Token');
    }
}
