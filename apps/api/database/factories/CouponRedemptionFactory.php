<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Billing\Models\CouponRedemption;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CouponRedemption> */
final class CouponRedemptionFactory extends Factory
{
    protected $model = CouponRedemption::class;

    public function definition(): array
    {
        return [
            'coupon_id' => CouponFactory::new(),
            'redeemed_at' => now(),
        ];
    }
}
