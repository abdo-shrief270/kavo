<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Models;

use App\Modules\Platform\Billing\Payments\Money;
use App\Modules\Platform\Billing\Payments\PaymentRail;
use App\Shared\Enums\PaymentStatus;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $reference
 * @property string $gateway
 * @property PaymentRail $rail
 * @property PaymentStatus $status
 * @property int $amount_cents
 * @property string $currency
 * @property ?string $gateway_reference
 * @property ?string $payment_reference
 * @property ?string $redirect_url
 * @property ?Carbon $expires_at
 * @property ?Carbon $settled_at
 * @property int $refunded_cents
 * @property ?string $last_error
 * @property array $metadata
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?string $public_reference
 */
final class PaymentIntent extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'reference', 'public_reference', 'gateway', 'rail', 'status', 'amount_cents', 'currency',
        'gateway_reference', 'payment_reference', 'redirect_url', 'expires_at', 'settled_at',
        'refunded_cents', 'last_error', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'rail' => PaymentRail::class,
            'expires_at' => 'datetime',
            'settled_at' => 'datetime',
            'metadata' => 'array',
            'amount_cents' => 'integer',
            'refunded_cents' => 'integer',
        ];
    }

    /** @return HasMany<PaymentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }

    public function money(): Money
    {
        return new Money($this->amount_cents, $this->currency);
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * Past its window and still unpaid. Checked rather than trusting a
     * scheduled sweep to have run — a customer must not be able to pay an
     * expired reference and have it applied.
     */
    public function hasExpired(): bool
    {
        return $this->status->isOpen()
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }

    public function isFullyRefunded(): bool
    {
        return $this->refunded_cents >= $this->amount_cents;
    }

    /** What the customer still has to do, for the storefront to render. */
    public function customerAction(): ?array
    {
        return match (true) {
            $this->redirect_url !== null => ['type' => 'redirect', 'url' => $this->redirect_url],
            $this->payment_reference !== null => [
                'type' => 'reference',
                'reference' => $this->payment_reference,
                'expires_at' => $this->expires_at?->toIso8601String(),
            ],
            default => null,
        };
    }
}
