<?php

declare(strict_types=1);

use App\Modules\Platform\Domains\Http\Controllers\TlsAskController;
use Illuminate\Support\Facades\Route;

/*
 | Infrastructure-facing endpoints. Not part of any public API surface and
 | never called by a browser.
 */

// Caddy asks before issuing a certificate for an unknown hostname. Must be
// fast, and must refuse by default — see TlsAskController.
Route::get('tls-ask', TlsAskController::class)->name('tls-ask');
