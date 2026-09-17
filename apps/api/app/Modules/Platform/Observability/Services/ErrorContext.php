<?php

declare(strict_types=1);

namespace App\Modules\Platform\Observability\Services;

use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Sentry\State\Scope;
use Throwable;

use function Sentry\configureScope;

/**
 * Attaches the context that makes an error report actionable.
 *
 * An untagged exception tells you something broke. The same exception tagged
 * with tenant, product and release tells you *whose* it is, in which vertical,
 * and which deploy introduced it — which is the difference between an alert
 * you can act on and one you scroll past.
 */
final class ErrorContext
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function apply(Request $request, string $requestId): void
    {
        if (! $this->sentryIsConfigured()) {
            return;
        }

        $tenant = $this->tenants->get();
        $user = $request->user();

        configureScope(function (Scope $scope) use ($request, $requestId, $tenant, $user): void {
            // Tags are indexed and searchable in Sentry; context is not. Put
            // anything you would want to filter an issue list by here.
            $scope->setTag('request_id', $requestId);
            $scope->setTag('tenant_id', (string) ($tenant?->getKey() ?? 'none'));
            $scope->setTag('tenant_slug', $tenant->slug ?? 'none');
            $scope->setTag('product', $tenant->product->value ?? 'platform');
            $scope->setTag('surface', $this->surfaceFor($request));

            if ($user !== null) {
                // id and role only. Email and name are personal data we have
                // no need to ship to a third party to debug a stack trace.
                $scope->setUser([
                    'id' => (string) $user->getKey(),
                    'is_platform_admin' => (bool) $user->is_platform_admin,
                ]);
            }

            $scope->setContext('request', [
                'route' => $request->route()?->getName(),
                'method' => $request->method(),
                'path' => $request->path(),
            ]);
        });
    }

    /**
     * Which of the three frontends a request came from. An error rate that
     * only moves on the storefront is a different problem from one that only
     * moves in the merchant console.
     */
    private function surfaceFor(Request $request): string
    {
        return match (true) {
            $request->is('api/admin/*') => 'admin',
            $request->is('api/storefront/*') => 'storefront',
            $request->is('webhooks/*') => 'webhooks',
            $request->is('internal/*') => 'internal',
            $request->is('api/*') => 'dashboard',
            default => 'web',
        };
    }

    private function sentryIsConfigured(): bool
    {
        if (config('sentry.dsn') === null || config('sentry.dsn') === '') {
            return false;
        }

        try {
            return function_exists('Sentry\configureScope');
        } catch (Throwable) {
            return false;
        }
    }
}
