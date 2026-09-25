<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Orders\Models;

use App\Modules\Commerce\Catalogue\Models\ProductVariant;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of intent: this variant, this many.
 *
 * No price column, on purpose — see Cart.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $cart_id
 * @property int $product_variant_id
 * @property int $quantity
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read ProductVariant $variant
 * @property-read Cart $cart
 */
final class CartItem extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = ['tenant_id', 'cart_id', 'product_variant_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    /** @return BelongsTo<Cart, $this> */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** Live, never stored. The price is whatever it is right now. */
    public function lineTotalCents(): int
    {
        return $this->variant->price_cents * $this->quantity;
    }
}
