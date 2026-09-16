<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Stores a gateway token only. Raw card data never reaches this database. */
#[Hidden(['gateway_token'])]
final class PaymentMethod extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = ['tenant_id', 'gateway', 'gateway_token', 'brand', 'last_four', 'expiry_month', 'expiry_year', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }
}
