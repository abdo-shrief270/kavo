<?php

use App\Modules\Platform\Identity\Http\Middleware\EnsurePlatformAdmin;
use App\Modules\Platform\Identity\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

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
        $middleware->statefulApi();

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'platform.admin' => EnsurePlatformAdmin::class,
        ]);

        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
