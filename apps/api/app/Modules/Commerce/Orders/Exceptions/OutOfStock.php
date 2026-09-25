<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Orders\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Someone else got there first.
 *
 * 409 Conflict rather than 422: the request was valid when it was made and the
 * shopper did nothing wrong — the world changed between rendering the cart and
 * paying for it. The offending line is named so the storefront can say which
 * item to adjust instead of showing a generic failure over the whole basket.
 */
final class OutOfStock extends RuntimeException
{
    public function __construct(
        public readonly string $sku,
        public readonly string $productName,
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct(sprintf(
            '%s (%s): %d requested, %d available.',
            $productName,
            $sku,
            $requested,
            $available,
        ));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->available === 0
                ? sprintf('%s sold out while you were checking out.', $this->productName)
                : sprintf('Only %d of %s left.', $this->available, $this->productName),
            'out_of_stock' => [
                'sku' => $this->sku,
                'product_name' => $this->productName,
                'requested' => $this->requested,
                'available' => $this->available,
            ],
        ], 409);
    }
}
