<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Catalogue\Models;

use App\Modules\Commerce\Catalogue\Enums\ProductStatus;
use App\Modules\Platform\Media\Models\Media;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A merchandised item. What a shopper browses; variants are what they buy.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $slug
 * @property ?string $description
 * @property ProductStatus $status
 * @property array $options
 * @property string $currency
 * @property ?Carbon $published_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?Carbon $deleted_at
 */
final class Product extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'name', 'slug', 'description', 'status',
        'options', 'currency', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'options' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Images, from the platform Media library.
     *
     * @return BelongsToMany<Media, $this>
     */
    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'product_media')
            ->withPivot(['position'])
            ->orderByPivot('position');
    }

    /**
     * Attach an image, stamping the pivot with this product's tenant.
     *
     * product_media is behind row-level security like every other tenant
     * table, and attach() writes the pivot through the query builder rather
     * than a model — so nothing stamps tenant_id and the policy rejects the
     * insert. The value cannot be declared on the relation with
     * withPivotValue either: eager loading builds the relation on a blank
     * instance, whose tenant_id is null.
     *
     * Calling ->media()->attach() directly is therefore wrong, but not
     * silently: it fails on the policy rather than writing a row nobody owns.
     */
    public function attachImage(Media $media, int $position = 0): void
    {
        $this->media()->attach($media->getKey(), [
            'tenant_id' => $this->tenant_id,
            'position' => $position,
        ]);
    }

    /**
     * What the storefront is allowed to show.
     *
     * A single scope rather than a status check at each call site: a listing
     * that forgets it publishes drafts, and a merchant's unfinished work goes
     * public.
     *
     * @param  Builder<Product>  $query
     */
    public function scopePublic(Builder $query): void
    {
        $query->where('status', ProductStatus::Active);
    }

    /** The lowest active variant price — what a listing shows as "from". */
    public function fromPriceCents(): ?int
    {
        $prices = $this->variants->where('is_active', true)->pluck('price_cents');

        return $prices->isEmpty() ? null : (int) $prices->min();
    }

    public function isInStock(): bool
    {
        return $this->variants->contains(fn (ProductVariant $variant): bool => $variant->isPurchasable());
    }
}
