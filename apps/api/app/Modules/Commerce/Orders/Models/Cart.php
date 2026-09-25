<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Orders\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A shopper's intent to buy, before any of it is agreed.
 *
 * Deliberately holds no prices and no reservations. Prices are read live from
 * the variant on every request, so a cart can never honour a figure the
 * merchant has since changed; stock is untouched until checkout, so one
 * abandoned cart cannot make a product unavailable to everybody else.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $token
 * @property string $currency
 * @property Carbon $expires_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Collection<int, CartItem> $items
 */
final class Cart extends Model
{
    use BelongsToTenant;
    use HasFactory;

    /** How long an untouched cart survives. Refreshed on every change. */
    public const LIFETIME_DAYS = 30;

    protected $fillable = ['tenant_id', 'token', 'currency', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /**
     * Opaque and unguessable. It is the only thing standing between a shopper
     * and their cart, so it is generated rather than derived from anything —
     * a token you can compute from a visitor's identity is not a secret.
     */
    public static function newToken(): string
    {
        return 'cart_'.Str::lower((string) Str::ulid());
    }

    /** @return HasMany<CartItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->orderBy('id');
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function touchExpiry(): void
    {
        $this->forceFill(['expires_at' => now()->addDays(self::LIFETIME_DAYS)])->save();
    }
}
