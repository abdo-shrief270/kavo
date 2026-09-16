<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Billing\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Subscription> */
final class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'plan_id' => PlanFactory::new(),
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ];
    }
}
