<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Billing\Models\PaymentEvent;
use App\Shared\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentEvent> */
final class PaymentEventFactory extends Factory
{
    protected $model = PaymentEvent::class;

    public function definition(): array
    {
        return [
            'payment_intent_id' => PaymentIntentFactory::new(),
            'from_status' => PaymentStatus::Pending,
            'to_status' => PaymentStatus::Succeeded,
            'source' => 'gateway',
            'payload' => [],
        ];
    }
}
