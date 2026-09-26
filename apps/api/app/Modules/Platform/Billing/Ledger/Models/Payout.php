<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Ledger\Models;

use App\Modules\Platform\Billing\Ledger\Enums\PayoutStatus;
use App\Modules\Platform\Billing\Payments\Money;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Money sent from the platform to a merchant.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $number
 * @property PayoutStatus $status
 * @property int $amount_cents
 * @property string $currency
 * @property string $method
 * @property array $destination
 * @property ?string $notes
 * @property ?string $failure_reason
 * @property Carbon $requested_at
 * @property ?Carbon $settled_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class Payout extends Model
{
    use BelongsToTenant;
    use HasFactory;

    /** Numbered per tenant from here, for the same reason orders are. */
    public const FIRST_NUMBER = 1000;

    /** What a merchant can actually be paid through in this market. */
    public const METHODS = ['bank_transfer', 'instapay', 'wallet', 'cash'];

    protected $fillable = [
        'tenant_id', 'number', 'status', 'amount_cents', 'currency',
        'method', 'destination', 'notes', 'failure_reason',
        'requested_at', 'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PayoutStatus::class,
            'amount_cents' => 'integer',
            'destination' => 'array',
            'requested_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function reference(): string
    {
        return 'PAY-'.$this->number;
    }

    public function money(): Money
    {
        return new Money($this->amount_cents, $this->currency);
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'reference' => $this->reference(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'method' => $this->method,
            // The destination is deliberately not published in a summary:
            // it is a bank account, and a list endpoint has no need of it.
            'notes' => $this->notes,
            'failure_reason' => $this->failure_reason,
            'requested_at' => $this->requested_at->toIso8601String(),
            'settled_at' => $this->settled_at?->toIso8601String(),
        ];
    }
}
