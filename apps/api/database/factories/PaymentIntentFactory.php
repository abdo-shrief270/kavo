<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Billing\Models\PaymentIntent;
use App\Modules\Platform\Billing\Payments\PaymentRail;
use App\Shared\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PaymentIntent> */
final class PaymentIntentFactory extends Factory
{
    protected $model = PaymentIntent::class;

    public function definition(): array
    {
        return [
            'reference' => 'order-'.Str::lower(Str::random(10)),
            'gateway' => 'fake',
            'rail' => PaymentRail::Card,
            'status' => PaymentStatus::Pending,
            'amount_cents' => 49900,
            'currency' => 'EGP',
            'metadata' => [],
        ];
    }

    public function awaitingOfflinePayment(): self
    {
        return $this->state(fn (): array => [
            'rail' => PaymentRail::Reference,
            'status' => PaymentStatus::AwaitingOfflinePayment,
            'payment_reference' => (string) fake()->numerify('##########'),
            'expires_at' => now()->addDays(3),
        ]);
    }

    public function succeeded(): self
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Succeeded,
            'gateway_reference' => 'gw_'.fake()->uuid(),
            'settled_at' => now(),
        ]);
    }
}
