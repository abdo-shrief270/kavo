<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Billing\Models\Plan;
use App\Shared\Enums\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Plan> */
final class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'code' => 'plan-'.Str::lower(Str::random(8)),
            'name' => fake()->word(),
            'product' => Product::Fashion,
            'interval' => 'month',
            'price_cents' => 49900,
            'currency' => 'EGP',
            'trial_days' => 14,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
