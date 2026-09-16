<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Billing\Models\InvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InvoiceLine> */
final class InvoiceLineFactory extends Factory
{
    protected $model = InvoiceLine::class;

    public function definition(): array
    {
        return [
            'invoice_id' => InvoiceFactory::new(),
            'description' => fake()->sentence(3),
            'quantity' => 1,
            'unit_price_cents' => 49900,
            'total_cents' => 49900,
        ];
    }
}
