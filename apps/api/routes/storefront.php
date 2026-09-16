<?php

declare(strict_types=1);

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
});
