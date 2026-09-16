<?php

declare(strict_types=1);

namespace App\Modules\Platform\Identity\Http\Middleware;

use App\Modules\Platform\Identity\Models\Tenant;
use App\Modules\Platform\Identity\Services\TenantLocator;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantDatabaseSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves the tenant for the request, in priority order:
 *
 *   1. verified custom domain hostname
 *   2. subdomain of the platform root domain
 *   3. X-Tenant header, checked against the caller's memberships
 *   4. the authenticated user's active tenant
 *
 * Header and active-tenant resolution are authorisation-checked: a user may
 * only resolve a tenant they belong to. Hostname resolution is not, because
 * the hostname is the tenant's public identity — that path serves the
 * storefront, which is anonymous by design.
 */
final class ResolveTenant
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantDatabaseSession $session,
        private readonly TenantLocator $locator,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->locator->byHostname($request->getHost())
            ?? $this->fromUser($request);

        if ($tenant === null) {
            throw new NotFoundHttpException('No tenant could be resolved for this request.');
        }

        if (! $tenant->canServeRequests()) {
            abort(403, 'This workspace is not active.');
        }

        $this->context->set($tenant);
        $this->session->bind($tenant->getKey());

        return $next($request);
    }

    private function fromUser(Request $request): ?Tenant
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        $requested = $request->header('X-Tenant');

        if ($requested !== null) {
            // Membership is the authorisation check. Without it any
            // authenticated user could name any tenant and be handed it.
            return $user->tenants()->where('slug', $requested)->first();
        }

        if ($user->active_tenant_id === null) {
            return null;
        }

        return $user->tenants()->whereKey($user->active_tenant_id)->first();
    }
}
