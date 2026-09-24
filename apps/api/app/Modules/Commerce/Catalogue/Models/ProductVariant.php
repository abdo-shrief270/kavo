<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Catalogue\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One buyable thing: a specific size and colour, with its own price and stock.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_id
 * @property string $sku
 * @property array $options
 * @property string $option_signature
 * @property int $price_cents
 * @property ?int $compare_at_price_cents
 * @property bool $track_inventory
 * @property int $stock_on_hand
 * @property int $stock_reserved
 * @property int $position
 * @property bool $is_active
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class ProductVariant extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'product_id', 'sku', 'options', 'option_signature',
        'price_cents', 'compare_at_price_cents', 'track_inventory',
        'stock_on_hand', 'stock_reserved', 'position', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'track_inventory' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * A canonical rendering of the option values, used as the uniqueness key.
     *
     * Sorted and case-folded so {"Size":"M","Colour":"Black"} and
     * {"colour":"black","size":"m"} collide rather than becoming two variants
     * that mean the same thing and split their stock between them.
     *
     * @param  array<string, string>  $options
     */
    public static function signatureFor(array $options): string
    {
        $pairs = [];

        foreach ($options as $axis => $value) {
            $pairs[] = mb_strtolower(trim((string) $axis)).':'.mb_strtolower(trim((string) $value));
        }

        sort($pairs);

        return implode('|', $pairs);
    }

    /** What may actually be sold: on hand, less what unpaid orders hold. */
    public function availableStock(): int
    {
        if (! $this->track_inventory) {
            return PHP_INT_MAX;
        }

        return max(0, $this->stock_on_hand - $this->stock_reserved);
    }

    public function isPurchasable(int $quantity = 1): bool
    {
        return $this->is_active && $this->availableStock() >= $quantity;
    }
}
