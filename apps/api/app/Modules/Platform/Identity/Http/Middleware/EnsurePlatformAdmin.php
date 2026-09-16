<?php

declare(strict_types=1);

namespace App\Modules\Platform\Identity\Http\Middleware;

use App\Shared\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the super-admin surface and puts the request into platform scope,
 * where the tenant global scope does not apply.
 *
 * Entering platform scope is the one legitimate way to read across tenants,
 * so every request that does it is audited.
 */
final class EnsurePlatformAdmin
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->is_platform_admin) {
            abort(403, 'Platform administration is restricted.');
        }

        $this->context->enterPlatformScope();

        return $next($request);
    }
}
