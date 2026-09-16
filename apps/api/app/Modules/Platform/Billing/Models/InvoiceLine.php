<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class InvoiceLine extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = ['tenant_id', 'invoice_id', 'description', 'quantity', 'unit_price_cents', 'total_cents'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
