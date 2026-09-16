<?php

declare(strict_types=1);

use App\Modules\Platform\Analytics\Http\Controllers\Admin\PlatformMetricsController;
use App\Modules\Platform\Audit\Http\Controllers\Admin\AuditLogController;
use App\Modules\Platform\Billing\Http\Controllers\Admin\PlanAdminController;
use App\Modules\Platform\Identity\Http\Controllers\Admin\TenantAdminController;
use Illuminate\Support\Facades\Route;

/*
 | Super-admin API (apps/admin, Vue SPA on its own origin).
 |
 | Separate origin and separate route file on purpose: the merchant dashboard
 | and the platform console should never share a session surface or be one
 | mis-scoped route away from each other.
 |
 | `platform.admin` gates access AND enters platform scope, where the tenant
 | global scope no longer applies. That is the single legitimate way to read
 | across tenants, so every request through here is audited.
 */

Route::middleware(['auth:sanctum', 'platform.admin'])->group(function (): void {
    Route::get('metrics', PlatformMetricsController::class)->name('metrics');

    Route::get('tenants', [TenantAdminController::class, 'index'])->name('tenants.index');
    Route::get('tenants/{tenant}', [TenantAdminController::class, 'show'])->name('tenants.show');
    Route::patch('tenants/{tenant}/status', [TenantAdminController::class, 'updateStatus'])->name('tenants.status');

    Route::get('plans', [PlanAdminController::class, 'index'])->name('plans.index');
    Route::post('plans', [PlanAdminController::class, 'store'])->name('plans.store');
    Route::patch('plans/{plan}', [PlanAdminController::class, 'update'])->name('plans.update');

    Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit.index');
});
