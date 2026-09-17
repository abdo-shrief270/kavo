<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** Stores a gateway token only. Raw card data never reaches this database. */
#[Hidden(['gateway_token'])]
/**
 * @property int $id
 * @property int $tenant_id
 * @property string $gateway
 * @property string $gateway_token
 * @property ?string $brand
 * @property ?string $last_four
 * @property ?int $expiry_month
 * @property ?int $expiry_year
 * @property bool $is_default
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
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
