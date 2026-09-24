<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Catalogue\Services;

use App\Modules\Commerce\Catalogue\Models\Product;
use App\Modules\Commerce\Catalogue\Models\ProductVariant;
use App\Shared\Contracts\Entitlements;
use App\Shared\Exceptions\QuotaExceeded;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creating, changing and removing products, with the two things a controller
 * must not be trusted to remember: the plan allowance and variant integrity.
 *
 * `products` is a *stock* metric — a plan saying 200 products means at any one
 * time — so deleting returns the allowance. Archiving does not: an archived
 * product still exists and still occupies a slot.
 */
final readonly class ProductWriter
{
    private const QUOTA_METRIC = 'products';

    public function __construct(
        private Entitlements $entitlements,
        private TenantContext $context,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $variants
     *
     * @throws QuotaExceeded
     */
    public function create(array $attributes, array $variants): Product
    {
        $tenant = $this->context->getOrFail('creating a product');

        // Charged before the write: a tenant at their ceiling never creates a
        // product they are not entitled to, then discovers it on the invoice.
        $result = $this->entitlements->consume($tenant, self::QUOTA_METRIC);

        if ($result->blocked()) {
            throw new QuotaExceeded($result);
        }

        try {
            return DB::transaction(function () use ($attributes, $variants): Product {
                $product = Product::create([
                    ...$attributes,
                    'slug' => $this->uniqueSlug($attributes['slug'] ?? $attributes['name']),
                ]);

                $this->syncVariants($product, $variants);

                return $product->load('variants');
            });
        } catch (\Throwable $e) {
            // The write failed, so the allowance was never used. Keeping it
            // charged would bill a tenant for a product that does not exist.
            $this->entitlements->release($tenant, self::QUOTA_METRIC);

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>|null  $variants  null leaves them alone
     */
    public function update(Product $product, array $attributes, ?array $variants = null): Product
    {
        return DB::transaction(function () use ($product, $attributes, $variants): Product {
            if (isset($attributes['slug']) && $attributes['slug'] !== $product->slug) {
                $attributes['slug'] = $this->uniqueSlug($attributes['slug'], $product->getKey());
            }

            $product->fill($attributes)->save();

            if ($variants !== null) {
                $this->syncVariants($product, $variants);
            }

            return $product->refresh()->load('variants');
        });
    }

    /** Deleting returns the slot to the tenant's allowance. Archiving does not. */
    public function delete(Product $product): void
    {
        $tenant = $this->context->getOrFail('deleting a product');

        DB::transaction(function () use ($product): void {
            $product->variants()->delete();
            $product->delete();
        });

        $this->entitlements->release($tenant, self::QUOTA_METRIC);
    }

    /**
     * Replace the variant set, refusing any two that mean the same thing.
     *
     * The database has a unique index on (product_id, option_signature), but
     * catching it here turns a 500 into a validation error that names the
     * offending combination.
     *
     * @param  list<array<string, mixed>>  $variants
     */
    private function syncVariants(Product $product, array $variants): void
    {
        $seen = [];
        $rows = [];

        foreach ($variants as $index => $variant) {
            $options = (array) ($variant['options'] ?? []);
            $signature = ProductVariant::signatureFor($options);

            if (isset($seen[$signature])) {
                throw ValidationException::withMessages([
                    "variants.{$index}.options" => sprintf(
                        'This combination is already used by variant %d. Two variants with the same options split their stock between them.',
                        $seen[$signature] + 1,
                    ),
                ]);
            }

            $seen[$signature] = $index;
            $rows[] = [...$variant, 'options' => $options, 'option_signature' => $signature, 'position' => $variant['position'] ?? $index];
        }

        $keep = [];

        foreach ($rows as $row) {
            $existing = $product->variants()
                ->where('option_signature', $row['option_signature'])
                ->first();

            if ($existing !== null) {
                // Stock is deliberately not in the fillable set here: it is
                // changed by receiving and by orders, never by editing the
                // product form, which would silently overwrite a reservation.
                $existing->fill(collect($row)->except(['stock_on_hand', 'stock_reserved'])->all())->save();
                $keep[] = $existing->getKey();

                continue;
            }

            $keep[] = $product->variants()->create($row)->getKey();
        }

        $product->variants()->whereNotIn('id', $keep ?: [0])->delete();
    }

    private function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'product';
        $slug = $base;
        $suffix = 2;

        while (Product::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
