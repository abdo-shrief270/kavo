<?php

declare(strict_types=1);

namespace App\Modules\Platform\Entitlements\Http\Middleware;

use App\Shared\Contracts\Entitlements;
use App\Shared\Exceptions\QuotaExceeded;
use App\Shared\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Meters a route with a fixed cost: `quota:orders` or `quota:orders,5`.
 *
 * For fixed-cost actions this is the whole integration — which is the point.
 * Metering that requires a controller change per endpoint is metering that
 * gets forgotten, and a forgotten meter is revenue the platform never bills.
 *
 * Variable-cost actions (an upload priced by size) call the service directly
 * instead; there is no way to know the cost before reading the request.
 */
final class EnforceQuota
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next, string $metric, string $amount = '1'): Response
    {
        $tenant = $this->context->getOrFail("metering {$metric}");

        $result = $this->entitlements->consume($tenant, $metric, max(1, (int) $amount));

        if ($result->blocked()) {
            throw new QuotaExceeded($result);
        }

        $response = $next($request);

        // Surfaced on every metered response so a client can show headroom
        // without a second round trip, and can see it shrinking before it
        // runs out.
        $response->headers->add([
            'X-Quota-Metric' => $result->metric,
            'X-Quota-Used' => (string) $result->used,
            'X-Quota-Limit' => $result->limit === null ? 'unlimited' : (string) $result->limit,
        ]);

        return $response;
    }
}
