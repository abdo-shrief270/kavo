<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Models;

use App\Shared\Enums\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** Platform catalogue, shared across tenants — deliberately not tenant-scoped. */
/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property Product $product
 * @property string $interval
 * @property int $price_cents
 * @property string $currency
 * @property int $trial_days
 * @property bool $is_active
 * @property int $sort_order
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class Plan extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'product', 'interval', 'price_cents', 'currency', 'trial_days', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'product' => Product::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<PlanFeature, $this> */
    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    public function featureLimit(string $key): ?PlanFeature
    {
        return $this->features->firstWhere('feature_key', $key);
    }
}
