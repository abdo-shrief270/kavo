<?php

declare(strict_types=1);

use App\Modules\Platform\Domains\Http\Controllers\DomainController;
use App\Modules\Platform\Identity\Http\Controllers\AuthController;
use App\Modules\Platform\Identity\Http\Controllers\TenantController;
use App\Modules\Platform\Themes\Http\Controllers\ThemeSettingsController;
use App\Modules\Platform\Webhooks\Http\Controllers\WebhookSubscriptionController;
use Illuminate\Support\Facades\Route;

/*
 | Merchant dashboard API (apps/dashboard, Vue SPA).
 |
 | Session auth via Sanctum: both dashboards are first-party origins, so
 | cookies are the right mechanism — nothing to store in localStorage and CSRF
 | protection comes with it.
 */

Route::post('register', [AuthController::class, 'register'])->middleware('guest')->name('register');
Route::post('login', [AuthController::class, 'login'])->middleware(['guest', 'throttle:6,1'])->name('login');
Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum')->name('logout');
Route::get('me', [AuthController::class, 'me'])->middleware('auth:sanctum')->name('me');

// Everything below resolves a tenant first. `tenant` middleware both sets the
// context and binds the RLS GUC, so no route here can read another tenant's
// rows even if a query forgets its scope.
Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('tenant', [TenantController::class, 'show'])->name('tenant.show');
    Route::patch('tenant', [TenantController::class, 'update'])->name('tenant.update');

    Route::get('domains', [DomainController::class, 'index'])->name('domains.index');
    Route::post('domains', [DomainController::class, 'store'])->name('domains.store');
    Route::post('domains/{domain}/verify', [DomainController::class, 'verify'])->name('domains.verify');
    Route::delete('domains/{domain}', [DomainController::class, 'destroy'])->name('domains.destroy');

    Route::get('themes', [ThemeSettingsController::class, 'index'])->name('themes.index');
    Route::put('themes', [ThemeSettingsController::class, 'update'])->name('themes.update');

    Route::get('webhooks/subscriptions', [WebhookSubscriptionController::class, 'index'])->name('webhooks.index');
    Route::post('webhooks/subscriptions', [WebhookSubscriptionController::class, 'store'])->name('webhooks.store');
    Route::delete('webhooks/subscriptions/{subscription}', [WebhookSubscriptionController::class, 'destroy'])->name('webhooks.destroy');
    Route::get('webhooks/deliveries', [WebhookSubscriptionController::class, 'deliveries'])->name('webhooks.deliveries');
    Route::post('webhooks/deliveries/{delivery}/retry', [WebhookSubscriptionController::class, 'retry'])->name('webhooks.retry');
});
