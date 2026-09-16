<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Models;

use App\Shared\Enums\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Platform catalogue, shared across tenants — deliberately not tenant-scoped. */
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

    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    public function featureLimit(string $key): ?PlanFeature
    {
        return $this->features->firstWhere('feature_key', $key);
    }
}
