<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Commerce\Catalogue\Models\ProductVariant;
use App\Modules\Commerce\Orders\Models\Cart;
use App\Modules\Commerce\Orders\Models\CartItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CartItem> */
final class CartItemFactory extends Factory
{
    protected $model = CartItem::class;

    public function definition(): array
    {
        return [
            'cart_id' => Cart::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'quantity' => 1,
        ];
    }
}
