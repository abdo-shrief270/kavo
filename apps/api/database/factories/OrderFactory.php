<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Commerce\Orders\Enums\InventoryState;
use App\Modules\Commerce\Orders\Enums\OrderStatus;
use App\Modules\Commerce\Orders\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Order> */
final class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * Order numbers are unique per tenant, and the isolation suite creates
     * orders for two tenants in the same test. A process-wide counter never
     * collides for either of them; fake()->numberBetween() collides rarely,
     * which is the worst kind of test failure.
     */
    private static int $nextNumber = Order::FIRST_NUMBER;

    public function definition(): array
    {
        $subtotal = fake()->numberBetween(9900, 499900);

        return [
            'number' => ++self::$nextNumber,
            'status' => OrderStatus::Pending,
            'inventory_state' => InventoryState::Reserved,
            'customer_name' => fake()->name(),
            'customer_email' => fake()->safeEmail(),
            'customer_phone' => '+2010'.fake()->numerify('########'),
            'shipping_address' => ['line1' => fake()->streetAddress(), 'city' => 'Cairo'],
            'subtotal_cents' => $subtotal,
            'shipping_cents' => 0,
            'total_cents' => $subtotal,
            'currency' => 'EGP',
            'placed_at' => now(),
        ];
    }

    public function paid(): self
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Paid,
            'inventory_state' => InventoryState::Committed,
            'paid_at' => now(),
        ]);
    }

    public function awaitingPayment(): self
    {
        return $this->state(fn (): array => ['status' => OrderStatus::AwaitingPayment]);
    }
}
