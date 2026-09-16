<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Invoice extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = ['tenant_id', 'subscription_id', 'number', 'status', 'subtotal_cents', 'discount_cents', 'tax_cents', 'total_cents', 'currency', 'gateway', 'gateway_reference', 'due_at', 'paid_at'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }
}
