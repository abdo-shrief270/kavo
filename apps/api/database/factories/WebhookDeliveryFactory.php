<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Webhooks\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WebhookDelivery> */
final class WebhookDeliveryFactory extends Factory
{
    protected $model = WebhookDelivery::class;

    public function definition(): array
    {
        return [
            'webhook_subscription_id' => WebhookSubscriptionFactory::new(),
            'event_type' => 'order.placed',
            'payload' => ['id' => fake()->uuid()],
            'attempt' => 0,
            'status' => 'pending',
        ];
    }
}
