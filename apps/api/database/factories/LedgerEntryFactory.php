<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Billing\Ledger\Enums\LedgerEntryType;
use App\Modules\Platform\Billing\Ledger\Models\LedgerEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LedgerEntry> */
final class LedgerEntryFactory extends Factory
{
    protected $model = LedgerEntry::class;

    public function definition(): array
    {
        return [
            'type' => LedgerEntryType::Sale,
            'amount_cents' => fake()->numberBetween(9900, 499900),
            'currency' => 'EGP',
            // Left null so the uniqueness index — which exists to stop one
            // order being credited twice — does not collide between unrelated
            // fixtures. Postgres treats nulls as distinct.
            'source_type' => null,
            'source_id' => null,
            'description' => fake()->sentence(4),
            'occurred_at' => now(),
        ];
    }

    public function commission(int $cents): self
    {
        return $this->state(fn (): array => [
            'type' => LedgerEntryType::Commission,
            'amount_cents' => -abs($cents),
        ]);
    }

    public function of(int $cents): self
    {
        return $this->state(fn (): array => ['amount_cents' => $cents]);
    }
}
