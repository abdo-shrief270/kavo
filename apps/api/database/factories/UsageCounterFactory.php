<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Entitlements\Models\UsageCounter;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UsageCounter> */
final class UsageCounterFactory extends Factory
{
    protected $model = UsageCounter::class;

    public function definition(): array
    {
        return [
            'metric_key' => fake()->randomElement(['orders', 'products', 'storage_mb']),
            'period_start' => now()->startOfMonth()->toDateString(),
            'value' => fake()->numberBetween(0, 500),
        ];
    }
}
