<?php

use App\Modules\Platform\Billing\Http\Middleware\EnsureIdempotency;
use App\Modules\Platform\Entitlements\Http\Middleware\EnforceQuota;
use App\Modules\Platform\Identity\Http\Middleware\EnsurePlatformAdmin;
use App\Modules\Platform\Identity\Http\Middleware\ResolveTenant;
use App\Modules\Platform\Observability\Http\Middleware\TracksRequestContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function (): void {
            Route::middleware('api')
                ->prefix('api/admin')
                ->name('admin.')
                ->group(__DIR__.'/../routes/admin.php');

            Route::middleware('api')
                ->prefix('api/storefront')
                ->name('storefront.')
                ->group(__DIR__.'/../routes/storefront.php');

            // Provider callbacks, not sessions: gateways cannot log in, so
            // these verify by signature instead and must not hit auth or CSRF.
            Route::prefix('webhooks')
                ->name('webhooks.')
                ->group(__DIR__.'/../routes/webhooks.php');

            Route::prefix('internal')
                ->name('internal.')
                ->group(__DIR__.'/../routes/internal.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // First in the global stack: everything logged or reported after this
        // point carries a correlation id, including failures raised by the
        // middleware that follows it.
        $middleware->prepend(TracksRequestContext::class);

        // Adds EnsureFrontendRequestsAreStateful to the api group. Requests
        // from SANCTUM_STATEFUL_DOMAINS get the session (and CSRF); anything
        // else falls through to bearer-token auth. Registering it twice —
        // here and again via api(prepend:) — runs the session stack twice.
        $middleware->statefulApi();

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'platform.admin' => EnsurePlatformAdmin::class,
            // quota:orders or quota:orders,5 — fixed-cost metering for a
            // route, so a new metered endpoint is one middleware away.
            'quota' => EnforceQuota::class,
            // Replays the first response for a repeated Idempotency-Key.
            'idempotent' => EnsureIdempotency::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
