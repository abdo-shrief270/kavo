<?php

declare(strict_types=1);

use App\Modules\Platform\Analytics\Http\Controllers\Admin\PlatformMetricsController;
use App\Modules\Platform\Audit\Http\Controllers\Admin\AuditLogController;
use App\Modules\Platform\Billing\Http\Controllers\Admin\PlanAdminController;
use App\Modules\Platform\Billing\Ledger\Http\Controllers\Admin\PayoutAdminController;
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

    /*
     | Merchant payouts. The platform is the merchant of record, so it holds
     | every shop's takings and owes them back.
     |
     | Addressed by tenant throughout, including the payout itself: route-model
     | binding for a tenant-scoped model runs in platform scope, where
     | row-level security correctly matches nothing, so the payout is loaded
     | inside the bound block instead.
     */
    Route::get('balances', [PayoutAdminController::class, 'balances'])->name('balances.index');
    Route::get('tenants/{tenant}/ledger', [PayoutAdminController::class, 'show'])->name('ledger.show');
    Route::post('tenants/{tenant}/payouts', [PayoutAdminController::class, 'store'])->name('payouts.store');
    Route::post('tenants/{tenant}/payouts/{payout}/settle', [PayoutAdminController::class, 'settle'])
        ->whereNumber('payout')
        ->name('payouts.settle');

    Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit.index');
});
