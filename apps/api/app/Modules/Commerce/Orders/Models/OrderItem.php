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
 * One line of the agreement, frozen at the moment it was agreed.
 *
 * Every display field is a column rather than a join. The variant link is kept
 * for returning stock and for "buy it again", and it is nullable: a merchant
 * who deletes a product has changed their catalogue, not the history of what
 * they sold.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $order_id
 * @property ?int $product_variant_id
 * @property string $product_name
 * @property string $variant_sku
 * @property array $options
 * @property int $unit_price_cents
 * @property int $quantity
 * @property int $total_cents
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read ?ProductVariant $variant
 * @property-read Order $order
 */
final class OrderItem extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'order_id', 'product_variant_id', 'product_name',
        'variant_sku', 'options', 'unit_price_cents', 'quantity', 'total_cents',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'unit_price_cents' => 'integer',
            'quantity' => 'integer',
            'total_cents' => 'integer',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'product_name' => $this->product_name,
            'sku' => $this->variant_sku,
            'options' => $this->options,
            'unit_price_cents' => $this->unit_price_cents,
            'quantity' => $this->quantity,
            'total_cents' => $this->total_cents,
        ];
    }
}
