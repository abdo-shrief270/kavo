<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Modules\Platform\Domains\Http\Controllers\TlsAskController;
use Illuminate\Support\Facades\Route;

/*
 | Infrastructure-facing endpoints. Not part of any public API surface and
 | never called by a browser.
 */

// Caddy asks before issuing a certificate for an unknown hostname. Must be
// fast, and must refuse by default — see TlsAskController.
Route::get('tls-ask', TlsAskController::class)->name('tls-ask');

// Deep dependency check. Uptime monitoring alerts on this; the deploy script
// gates rollback on Laravel's /up instead, so a Redis blip cannot roll back a
// healthy release.
Route::get('health', HealthController::class)->name('health');
