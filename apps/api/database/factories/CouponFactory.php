<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Billing\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Coupon> */
final class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('SAVE####')),
            'type' => 'percent',
            'value' => 10,
            'is_active' => true,
        ];
    }
}
