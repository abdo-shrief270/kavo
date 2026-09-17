<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** Platform catalogue — redemptions are the tenant-scoped side. */
/**
 * @property int $id
 * @property string $code
 * @property string $type
 * @property int $value
 * @property ?int $max_redemptions
 * @property ?Carbon $expires_at
 * @property bool $is_active
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class Coupon extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'type', 'value', 'max_redemptions', 'expires_at', 'is_active'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'is_active' => 'boolean'];
    }

    public function isRedeemable(): bool
    {
        return $this->is_active && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
