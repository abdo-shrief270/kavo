<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Orders\Services;

use App\Modules\Commerce\Catalogue\Enums\ProductStatus;
use App\Modules\Commerce\Catalogue\Models\ProductVariant;
use App\Modules\Commerce\Orders\Models\Cart;
use App\Modules\Commerce\Orders\Models\CartItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reading and changing a shopper's cart.
 *
 * Two rules the controllers are not trusted to remember.
 *
 * A cart may only ever contain something the storefront is actually selling:
 * an active variant of an active product. A merchant who unpublishes a product
 * has withdrawn it, and a cart built the day before must not be a way around
 * that — nor a way to read a draft product's name and price.
 *
 * And quantity is checked against what is available *now*, not against what
 * was available when the line was added. Availability at checkout is still the
 * only figure that binds, because stock is not held until then; this check
 * exists so a shopper learns on the product page rather than on the payment
 * page.
 */
final readonly class CartManager
{
    /** One line cannot be more than this. A typo, not a wholesale order. */
    private const MAX_QUANTITY = 99;

    /** Find a cart by its token, or start one. Never returns an expired cart. */
    public function resolve(?string $token): Cart
    {
        $cart = $token === null || $token === ''
            ? null
            : Cart::query()->with('items.variant')->where('token', $token)->first();

        if ($cart !== null && ! $cart->hasExpired()) {
            return $cart;
        }

        return Cart::create([
            'token' => Cart::newToken(),
            'expires_at' => now()->addDays(Cart::LIFETIME_DAYS),
        ]);
    }

    /**
     * Add a variant, or raise the quantity if it is already in the cart.
     *
     * @throws ValidationException
     */
    public function add(Cart $cart, int $variantId, int $quantity): Cart
    {
        $variant = $this->sellableVariant($variantId);

        $existing = $cart->items()->where('product_variant_id', $variant->getKey())->first();

        // Spelled out rather than `?->quantity ?? 0`: static analysis reads
        // first() on this builder as non-nullable, so the nullsafe reads as
        // dead code even though the null is the ordinary case here.
        $wanted = ($existing === null ? 0 : $existing->quantity) + $quantity;

        $this->assertAvailable($variant, $wanted);

        DB::transaction(function () use ($cart, $variant, $existing, $wanted): void {
            if ($existing !== null) {
                $existing->forceFill(['quantity' => $wanted])->save();
            } else {
                CartItem::create([
                    'cart_id' => $cart->getKey(),
                    'product_variant_id' => $variant->getKey(),
                    'quantity' => $wanted,
                ]);
            }

            // A cart in use is not an abandoned cart.
            $cart->touchExpiry();
        });

        return $this->reload($cart);
    }

    /** Set an exact quantity. Zero removes the line. */
    public function setQuantity(Cart $cart, CartItem $item, int $quantity): Cart
    {
        if ($quantity <= 0) {
            return $this->remove($cart, $item);
        }

        $this->assertAvailable($this->sellableVariant($item->product_variant_id), $quantity);

        $item->forceFill(['quantity' => $quantity])->save();
        $cart->touchExpiry();

        return $this->reload($cart);
    }

    public function remove(Cart $cart, CartItem $item): Cart
    {
        $item->delete();
        $cart->touchExpiry();

        return $this->reload($cart);
    }

    /**
     * The cart as the storefront renders it, priced at today's prices.
     *
     * `available` is published per line so a shopper is told what changed
     * before they try to pay, rather than being declined at checkout with no
     * explanation of which line was the problem.
     *
     * @return array<string, mixed>
     */
    public function present(Cart $cart): array
    {
        $lines = $cart->items->map(function (CartItem $item): array {
            $variant = $item->variant;

            return [
                'id' => $item->getKey(),
                'variant_id' => $variant->getKey(),
                'product_name' => $variant->product->name,
                'product_slug' => $variant->product->slug,
                'sku' => $variant->sku,
                'options' => $variant->options,
                'unit_price_cents' => $variant->price_cents,
                'quantity' => $item->quantity,
                'total_cents' => $item->lineTotalCents(),
                'available' => $variant->isPurchasable($item->quantity),
            ];
        });

        return [
            'token' => $cart->token,
            'currency' => $cart->currency,
            'items' => $lines->all(),
            'item_count' => (int) $lines->sum('quantity'),
            'subtotal_cents' => (int) $lines->sum('total_cents'),
            // One unavailable line is enough to stop the whole checkout, so
            // the storefront should not offer the button.
            'checkout_ready' => $lines->isNotEmpty() && $lines->every(fn (array $line): bool => $line['available']),
        ];
    }

    /**
     * Only what the storefront is publicly selling.
     *
     * The product join is the point: an active variant of a draft product is
     * not for sale, and checking only the variant would let a cart quote the
     * price of something never published.
     */
    private function sellableVariant(int $variantId): ProductVariant
    {
        $variant = ProductVariant::query()
            ->with('product')
            ->where('is_active', true)
            ->whereHas('product', fn ($query) => $query->where('status', ProductStatus::Active))
            ->find($variantId);

        if ($variant === null) {
            throw ValidationException::withMessages([
                'variant_id' => 'That item is no longer for sale.',
            ]);
        }

        return $variant;
    }

    private function assertAvailable(ProductVariant $variant, int $quantity): void
    {
        if ($quantity > self::MAX_QUANTITY) {
            throw ValidationException::withMessages([
                'quantity' => 'You can order at most '.self::MAX_QUANTITY.' of one item.',
            ]);
        }

        if (! $variant->isPurchasable($quantity)) {
            throw ValidationException::withMessages([
                'quantity' => $variant->availableStock() === 0
                    ? 'That item is out of stock.'
                    : 'Only '.$variant->availableStock().' left.',
            ]);
        }
    }

    private function reload(Cart $cart): Cart
    {
        return $cart->refresh()->load('items.variant.product');
    }
}
