<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Commerce\Catalogue\Models\ProductVariant;
use App\Modules\Commerce\Orders\Models\Order;
use App\Modules\Commerce\Orders\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OrderItem> */
final class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        $price = fake()->numberBetween(9900, 199900);

        return [
            'order_id' => Order::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'product_name' => Str::title(fake()->words(2, true)),
            'variant_sku' => 'SKU-'.Str::upper(Str::random(8)),
            'options' => ['Size' => 'M'],
            'unit_price_cents' => $price,
            'quantity' => 1,
            'total_cents' => $price,
        ];
    }
}
