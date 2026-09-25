<?php

declare(strict_types=1);

use App\Modules\Commerce\Catalogue\Http\Controllers\Storefront\CatalogueController;
use App\Modules\Commerce\Orders\Http\Controllers\Storefront\CartController;
use App\Modules\Commerce\Orders\Http\Controllers\Storefront\CheckoutController;
use App\Modules\Platform\Themes\Http\Controllers\StorefrontConfigController;
use Illuminate\Support\Facades\Route;

/*
 | Public storefront API (apps/storefront, Nuxt 4 SSR).
 |
 | Anonymous by design: the tenant is resolved from the hostname, which is the
 | tenant's own public identity. No auth middleware, so `tenant` resolution
 | here never falls through to a user's active tenant.
 */

Route::middleware('tenant')->group(function (): void {
    Route::get('config', StorefrontConfigController::class)->name('config');

    Route::get('products', [CatalogueController::class, 'index'])->name('products.index');
    Route::get('products/{slug}', [CatalogueController::class, 'show'])->name('products.show');

    /*
     | The basket. Identified by an opaque X-Cart-Token rather than a session:
     | the storefront renders on a different origin and would otherwise need a
     | cross-origin credential on every request.
     */
    Route::get('cart', [CartController::class, 'show'])->name('cart.show');
    Route::post('cart/items', [CartController::class, 'store'])->name('cart.items.store');
    Route::patch('cart/items/{item}', [CartController::class, 'update'])->name('cart.items.update');
    Route::delete('cart/items/{item}', [CartController::class, 'destroy'])->name('cart.items.destroy');

    Route::get('checkout/rails', [CheckoutController::class, 'rails'])->name('checkout.rails');
    // Idempotent: a checkout retried over a flaky mobile connection must not
    // become two orders, two reservations and two charges — which is the
    // normal case in this market, not the edge case.
    Route::post('checkout', [CheckoutController::class, 'store'])->middleware('idempotent')->name('checkout.store');

    // The "where is my order" page. Offline rails mean a customer comes back
    // to this days after placing it.
    // Constrained to digits: the segment is matched against a bigint column,
    // and Postgres answers a non-numeric comparison with an error rather than
    // no rows — a 500 on a URL anyone can type.
    Route::get('orders/{number}', [CheckoutController::class, 'show'])
        ->whereNumber('number')
        ->name('orders.show');
});
