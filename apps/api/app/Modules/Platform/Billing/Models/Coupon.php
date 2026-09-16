<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Platform catalogue — redemptions are the tenant-scoped side. */
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
