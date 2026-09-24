<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Catalogue\Http\Controllers\Storefront;

use App\Modules\Commerce\Catalogue\Models\Product;
use App\Modules\Commerce\Catalogue\Models\ProductVariant;
use App\Shared\Contracts\AnalyticsIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What a shopper sees. No authentication: the tenant comes from the hostname.
 *
 * Every query goes through scopePublic, so a draft or archived product is not
 * reachable by guessing its slug — the status check is not a filter the
 * listing applies and the detail page forgets.
 */
final class CatalogueController
{
    public function index(Request $request, AnalyticsIngestor $analytics): JsonResponse
    {
        $products = Product::query()
            ->public()
            ->with(['variants' => fn ($query) => $query->where('is_active', true), 'media'])
            ->when(
                $request->string('q')->toString(),
                fn ($query, string $term) => $query->where('name', 'ilike', '%'.$term.'%'),
            )
            ->latest('published_at')
            ->latest('id')
            ->paginate(24);

        $analytics->record('storefront.catalogue_viewed', ['results' => $products->total()]);

        return response()->json([
            'products' => $products->getCollection()->map(fn (Product $product): array => $this->summarise($product)),
            'meta' => [
                'total' => $products->total(),
                'per_page' => $products->perPage(),
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
            ],
        ])->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=300');
    }

    public function show(string $slug, AnalyticsIngestor $analytics): JsonResponse
    {
        $product = Product::query()
            ->public()
            ->with(['variants' => fn ($query) => $query->where('is_active', true), 'media'])
            ->where('slug', $slug)
            ->first();

        if ($product === null) {
            // 404 rather than 403: whether a slug exists as someone's draft is
            // not a shopper's business.
            throw new NotFoundHttpException('No such product.');
        }

        $analytics->record('storefront.product_viewed', ['slug' => $slug]);

        return response()->json([
            'product' => [
                ...$this->summarise($product),
                'description' => $product->description,
                'options' => $product->options,
                'variants' => $product->variants->map(fn (ProductVariant $variant): array => [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'options' => $variant->options,
                    'price_cents' => $variant->price_cents,
                    'compare_at_price_cents' => $variant->compare_at_price_cents,
                    // The number, not the stock level: how much a competitor
                    // has left is not something to publish.
                    'in_stock' => $variant->isPurchasable(),
                ])->all(),
            ],
        ])->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=300');
    }

    /** @return array<string, mixed> */
    private function summarise(Product $product): array
    {
        return [
            'slug' => $product->slug,
            'name' => $product->name,
            'currency' => $product->currency,
            'from_price_cents' => $product->fromPriceCents(),
            'in_stock' => $product->isInStock(),
            'images' => $product->media->map(fn ($media): array => [
                'url' => $media->url(),
                'alt' => $product->name,
            ])->all(),
        ];
    }
}
