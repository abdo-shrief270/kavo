<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property ?int $subscription_id
 * @property string $number
 * @property string $status
 * @property int $subtotal_cents
 * @property int $discount_cents
 * @property int $tax_cents
 * @property int $total_cents
 * @property string $currency
 * @property ?string $gateway
 * @property ?string $gateway_reference
 * @property ?Carbon $due_at
 * @property ?Carbon $paid_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class Invoice extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = ['tenant_id', 'subscription_id', 'number', 'status', 'subtotal_cents', 'discount_cents', 'tax_cents', 'total_cents', 'currency', 'gateway', 'gateway_reference', 'due_at', 'paid_at'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }
}
