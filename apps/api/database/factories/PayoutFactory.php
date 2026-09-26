<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Billing\Ledger\Enums\PayoutStatus;
use App\Modules\Platform\Billing\Ledger\Models\Payout;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Payout> */
final class PayoutFactory extends Factory
{
    protected $model = Payout::class;

    /**
     * Payout numbers are unique per tenant and the isolation suite creates one
     * for two tenants in the same test. A process-wide counter never collides
     * for either; a random number collides rarely, which is the worst kind of
     * test failure.
     */
    private static int $nextNumber = Payout::FIRST_NUMBER;

    public function definition(): array
    {
        return [
            'number' => ++self::$nextNumber,
            'status' => PayoutStatus::Pending,
            'amount_cents' => fake()->numberBetween(50000, 500000),
            'currency' => 'EGP',
            'method' => 'instapay',
            'destination' => ['handle' => fake()->userName().'@instapay'],
            'requested_at' => now(),
        ];
    }

    public function paid(): self
    {
        return $this->state(fn (): array => ['status' => PayoutStatus::Paid, 'settled_at' => now()]);
    }
}
