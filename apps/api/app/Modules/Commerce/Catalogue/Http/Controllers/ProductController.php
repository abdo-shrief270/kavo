<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Catalogue\Http\Controllers;

use App\Modules\Commerce\Catalogue\Enums\ProductStatus;
use App\Modules\Commerce\Catalogue\Models\Product;
use App\Modules\Commerce\Catalogue\Services\ProductWriter;
use App\Modules\Platform\Audit\Services\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The merchant's catalogue. Tenant-scoped by middleware and by RLS. */
final class ProductController
{
    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->with(['variants', 'media'])
            ->when(
                $request->string('status')->toString(),
                fn ($query, string $status) => $query->where('status', $status),
            )
            ->when(
                $request->string('q')->toString(),
                fn ($query, string $term) => $query->where('name', 'ilike', '%'.$term.'%'),
            )
            ->latest('id')
            ->paginate(25);

        return response()->json($products);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json(['product' => $product->load(['variants', 'media'])]);
    }

    public function store(Request $request, ProductWriter $writer, AuditRecorder $audit): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $product = $writer->create(
            collect($validated)->except('variants')->all(),
            $validated['variants'],
        );

        $audit->record('product.created', $product);

        return response()->json(['product' => $product], 201);
    }

    public function update(Request $request, Product $product, ProductWriter $writer, AuditRecorder $audit): JsonResponse
    {
        $validated = $request->validate($this->rules(updating: true));

        $updated = $writer->update(
            $product,
            collect($validated)->except('variants')->all(),
            $validated['variants'] ?? null,
        );

        $audit->record('product.updated', $updated);

        return response()->json(['product' => $updated]);
    }

    public function destroy(Product $product, ProductWriter $writer, AuditRecorder $audit): JsonResponse
    {
        $audit->record('product.deleted', $product);
        $writer->delete($product);

        return response()->json(['message' => 'Product removed.']);
    }

    /** @return array<string, mixed> */
    private function rules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:200'],
            'slug' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:20000'],
            'status' => ['sometimes', Rule::enum(ProductStatus::class)],
            'currency' => ['sometimes', 'string', 'size:3'],

            // The axes, e.g. [{"name":"Size","values":["S","M"]}].
            'options' => ['sometimes', 'array', 'max:3'],
            'options.*.name' => ['required', 'string', 'max:50'],
            'options.*.values' => ['required', 'array', 'min:1', 'max:50'],
            'options.*.values.*' => ['string', 'max:50'],

            // At least one: a product with no variant has nothing to sell and
            // no price to show.
            'variants' => [$required, 'array', 'min:1', 'max:100'],
            'variants.*.sku' => ['required', 'string', 'max:64'],
            'variants.*.options' => ['sometimes', 'array'],
            'variants.*.price_cents' => ['required', 'integer', 'min:0'],
            'variants.*.compare_at_price_cents' => ['nullable', 'integer', 'min:0'],
            'variants.*.track_inventory' => ['sometimes', 'boolean'],
            'variants.*.stock_on_hand' => ['sometimes', 'integer', 'min:0'],
            'variants.*.is_active' => ['sometimes', 'boolean'],
            'variants.*.position' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
