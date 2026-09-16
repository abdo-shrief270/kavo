<?php

declare(strict_types=1);

use App\Modules\Platform\Webhooks\Http\Controllers\InboundWebhookController;
use Illuminate\Support\Facades\Route;

/*
 | Inbound provider callbacks.
 |
 | No auth and no CSRF: providers cannot hold a session. Each request is
 | authenticated by HMAC over the raw body instead, and a provider with no
 | configured verifier is refused rather than accepted unverified.
 */

Route::post('{provider}', InboundWebhookController::class)
    ->where('provider', '[a-z0-9_-]+')
    ->name('inbound');
