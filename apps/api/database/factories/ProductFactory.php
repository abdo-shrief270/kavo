<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Commerce\Catalogue\Enums\ProductStatus;
use App\Modules\Commerce\Catalogue\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Product> */
final class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = Str::title(fake()->words(2, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'description' => fake()->sentence(12),
            'status' => ProductStatus::Draft,
            'options' => [['name' => 'Size', 'values' => ['S', 'M', 'L']]],
            'currency' => 'EGP',
        ];
    }

    public function published(): self
    {
        return $this->state(fn (): array => [
            'status' => ProductStatus::Active,
            'published_at' => now(),
        ]);
    }
}
