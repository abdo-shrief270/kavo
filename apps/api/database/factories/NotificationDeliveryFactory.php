<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Notifications\Models\NotificationDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotificationDelivery> */
final class NotificationDeliveryFactory extends Factory
{
    protected $model = NotificationDelivery::class;

    public function definition(): array
    {
        return [
            'channel' => 'whatsapp',
            'event_type' => 'order.placed',
            'recipient' => fake()->e164PhoneNumber(),
            'template_code' => 'order_placed',
            'status' => 'sent',
            'provider_message_id' => 'msg_'.fake()->uuid(),
            'payload' => [],
            'attempts' => 1,
            'sent_at' => now(),
        ];
    }
}
