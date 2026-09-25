<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Catalogue\Http\Controllers;

use App\Modules\Commerce\Catalogue\Models\Product;
use App\Modules\Commerce\Catalogue\Models\ProductVariant;
use App\Modules\Commerce\Catalogue\Services\Inventory;
use App\Modules\Platform\Audit\Services\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Changing what is on the shelf.
 *
 * Separate from the product editor on purpose. The editor's variant sync
 * deliberately refuses to touch stock — a merchant who opens the form, takes a
 * phone call and saves twenty minutes later would otherwise overwrite every
 * sale made in between — which left the catalogue with no way to restock at
 * all. This is that way, and it is the only one.
 *
 * Two motions, because a warehouse has two. Receiving is a signed delta and is
 * safe under concurrency. A stock take is an absolute figure, and is the one
 * that needs a guard: counting fewer units than unpaid orders are holding is a
 * conversation with a customer, not a number to overwrite.
 */
final class VariantStockController
{
    public function update(
        Request $request,
        Product $product,
        ProductVariant $variant,
        Inventory $inventory,
        AuditRecorder $audit,
    ): JsonResponse {
        $validated = $request->validate([
            // Exactly one. Both together is a client bug, and guessing which
            // one was meant is how stock ends up wrong.
            'adjust' => ['required_without:on_hand', 'prohibits:on_hand', 'integer', 'not_in:0'],
            'on_hand' => ['required_without:adjust', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $before = $variant->stock_on_hand;

        $applied = array_key_exists('adjust', $validated)
            ? $inventory->receive($variant->getKey(), (int) $validated['adjust'])
            : $inventory->count($variant->getKey(), (int) $validated['on_hand']);

        if (! $applied) {
            return response()->json([
                'message' => sprintf(
                    'That would leave %d on the shelf with %d already promised to unpaid orders.',
                    array_key_exists('adjust', $validated) ? $before + (int) $validated['adjust'] : (int) $validated['on_hand'],
                    $variant->stock_reserved,
                ),
                'stock_reserved' => $variant->stock_reserved,
            ], 422);
        }

        $variant->refresh();

        // Stock movements are exactly what an audit log is for: "where did
        // those twelve go" is answerable or it is not.
        $audit->record('variant.stock_adjusted', $variant, ['stock_on_hand' => $before], [
            'stock_on_hand' => $variant->stock_on_hand,
            'reason' => $validated['reason'] ?? null,
        ]);

        return response()->json([
            'variant' => [
                'id' => $variant->getKey(),
                'sku' => $variant->sku,
                'stock_on_hand' => $variant->stock_on_hand,
                'stock_reserved' => $variant->stock_reserved,
                'available' => $variant->availableStock(),
            ],
        ]);
    }
}
