<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Commerce\Orders\Models\Cart;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Cart> */
final class CartFactory extends Factory
{
    protected $model = Cart::class;

    public function definition(): array
    {
        return [
            'token' => Cart::newToken(),
            'currency' => 'EGP',
            'expires_at' => now()->addDays(Cart::LIFETIME_DAYS),
        ];
    }

    public function expired(): self
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }
}
