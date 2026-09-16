<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Billing\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Invoice> */
final class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'number' => 'INV-'.fake()->unique()->numerify('########'),
            'status' => 'draft',
            'subtotal_cents' => 49900,
            'total_cents' => 49900,
            'currency' => 'EGP',
        ];
    }
}
