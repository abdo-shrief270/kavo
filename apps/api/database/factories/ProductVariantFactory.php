<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Commerce\Catalogue\Models\Product;
use App\Modules\Commerce\Catalogue\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ProductVariant> */
final class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        $size = fake()->randomElement(['S', 'M', 'L']);

        return [
            'product_id' => Product::factory(),
            'sku' => 'SKU-'.Str::upper(Str::random(8)),
            'options' => ['Size' => $size],
            'option_signature' => ProductVariant::signatureFor(['Size' => $size]),
            'price_cents' => fake()->numberBetween(9900, 199900),
            'track_inventory' => true,
            'stock_on_hand' => 10,
            'stock_reserved' => 0,
            'is_active' => true,
        ];
    }

    public function outOfStock(): self
    {
        return $this->state(fn (): array => ['stock_on_hand' => 0, 'stock_reserved' => 0]);
    }
}
