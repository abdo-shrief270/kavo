<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Billing\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentMethod> */
final class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

    public function definition(): array
    {
        return [
            'gateway' => 'paymob',
            // A gateway token, never card data — the table is modelled so raw
            // PAN has nowhere to go even by accident.
            'gateway_token' => 'tok_'.fake()->uuid(),
            'brand' => 'visa',
            'last_four' => (string) fake()->numberBetween(1000, 9999),
            'expiry_month' => 12,
            'expiry_year' => (int) now()->addYears(2)->format('Y'),
            'is_default' => true,
        ];
    }
}
