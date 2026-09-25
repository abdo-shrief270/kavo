<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Catalogue\Services;

use App\Modules\Commerce\Catalogue\Models\Product;
use App\Modules\Commerce\Catalogue\Models\ProductVariant;
use App\Shared\Contracts\Entitlements;
use App\Shared\Exceptions\QuotaExceeded;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
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
     * Two uniqueness rules apply and both are enforced by the database. What
     * this method adds is a 422 naming the offending row instead of a 500
     * naming a Postgres constraint — which is what a merchant reusing a SKU
     * got, and reusing a SKU is an ordinary mistake rather than an exotic one.
     *
     * @param  list<array<string, mixed>>  $variants
     */
    private function syncVariants(Product $product, array $variants): void
    {
        $seenSignature = [];
        $seenSku = [];
        $rows = [];

        foreach ($variants as $index => $variant) {
            $options = (array) ($variant['options'] ?? []);
            $signature = ProductVariant::signatureFor($options);
            $sku = trim((string) ($variant['sku'] ?? ''));

            if (isset($seenSignature[$signature])) {
                throw ValidationException::withMessages([
                    "variants.{$index}.options" => sprintf(
                        'This combination is already used by variant %d. Two variants with the same options split their stock between them.',
                        $seenSignature[$signature] + 1,
                    ),
                ]);
            }

            // Within this one submission. The cross-product case cannot be
            // seen from here and is caught on the write below.
            if (isset($seenSku[$sku])) {
                throw ValidationException::withMessages([
                    "variants.{$index}.sku" => sprintf('Variant %d already uses this SKU.', $seenSku[$sku] + 1),
                ]);
            }

            $seenSignature[$signature] = $index;
            $seenSku[$sku] = $index;
            $rows[] = [...$variant, 'options' => $options, 'option_signature' => $signature, 'position' => $variant['position'] ?? $index];
        }

        $keep = [];

        foreach ($rows as $index => $row) {
            $existing = $product->variants()
                ->where('option_signature', $row['option_signature'])
                ->first();

            $keep[] = $this->writeVariant($product, $existing, $row, $index);
        }

        $product->variants()->whereNotIn('id', $keep ?: [0])->delete();
    }

    /**
     * Write one variant, turning a SKU collision into a validation error.
     *
     * The collision is only visible at the write, because the SKU may belong
     * to a different product of the same tenant — which nothing in the
     * submitted payload can see.
     *
     * @param  array<string, mixed>  $row
     */
    private function writeVariant(Product $product, ?ProductVariant $existing, array $row, int $index): int
    {
        try {
            if ($existing !== null) {
                // Stock is deliberately not in the fillable set here: it is
                // changed by receiving and by orders, never by editing the
                // product form, which would silently overwrite a reservation.
                $existing->fill(collect($row)->except(['stock_on_hand', 'stock_reserved'])->all())->save();

                return (int) $existing->getKey();
            }

            return (int) $product->variants()->create($row)->getKey();
        } catch (QueryException $e) {
            // 23505 on the SKU index: this code is already in use somewhere in
            // this shop.
            if ($e->getCode() === '23505' && str_contains((string) $e->getMessage(), 'sku')) {
                throw ValidationException::withMessages([
                    "variants.{$index}.sku" => sprintf('The SKU %s is already used by another product.', $row['sku'] ?? ''),
                ]);
            }

            throw $e;
        }
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
