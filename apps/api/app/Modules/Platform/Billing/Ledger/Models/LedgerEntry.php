<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Ledger\Models;

use App\Modules\Platform\Billing\Ledger\Enums\LedgerEntryType;
use App\Modules\Platform\Billing\Payments\Money;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One line of what the platform owes a merchant. Append-only.
 *
 * Append-only is the whole value of a ledger: a balance you can edit is a
 * balance nobody can dispute, and "what did we owe them in March" stops being
 * answerable the moment a row can change. A correction is a new Adjustment
 * entry that says who made it and why, not an edit to the row that was wrong.
 *
 * Enforced in the model rather than only by convention, because the mistake it
 * prevents is silent and permanent.
 *
 * @property int $id
 * @property int $tenant_id
 * @property LedgerEntryType $type
 * @property int $amount_cents
 * @property string $currency
 * @property ?string $source_type
 * @property ?int $source_id
 * @property string $description
 * @property Carbon $occurred_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class LedgerEntry extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'type', 'amount_cents', 'currency',
        'source_type', 'source_id', 'description', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'amount_cents' => 'integer',
            'source_id' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Ledger entries are append-only. Correct one with an Adjustment entry instead.');
        });

        self::deleting(static function (): never {
            throw new LogicException('Ledger entries are append-only. Reverse one with an Adjustment entry instead.');
        });
    }

    public function money(): Money
    {
        // Money refuses a negative amount, correctly — it is a sum owed, not a
        // direction. The sign is the ledger's business and is read from the
        // column, so this exposes the magnitude only.
        return new Money(abs($this->amount_cents), $this->currency);
    }

    public function isCredit(): bool
    {
        return $this->amount_cents > 0;
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'label' => $this->type->label(),
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'description' => $this->description,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }
}
