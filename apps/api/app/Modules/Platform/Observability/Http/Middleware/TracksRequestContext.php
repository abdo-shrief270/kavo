<?php

declare(strict_types=1);

namespace App\Modules\Platform\Observability\Http\Middleware;

use App\Modules\Platform\Observability\Services\ErrorContext;
use App\Modules\Platform\Observability\Services\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the correlation id that ties a log line, an error report and a
 * client-side complaint together.
 *
 * Accepts an inbound X-Request-Id so a trace survives a hop from the
 * storefront's SSR server into the API — without that, one page render
 * produces two unrelated sets of logs.
 */
final class TracksRequestContext
{
    public function __construct(
        private readonly RequestContext $context,
        private readonly ErrorContext $errors,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->inboundId($request) ?? (string) Str::uuid();

        $this->context->setRequestId($requestId);
        $this->errors->apply($request, $requestId);

        $response = $next($request);

        // Echoed back so a user reporting a problem can quote an id that
        // finds the exact request in the logs.
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }

    /**
     * Only accepted from trusted proxies. A client-supplied id would
     * otherwise let anyone collide with or poison another request's trace.
     */
    private function inboundId(Request $request): ?string
    {
        if (! $request->isFromTrustedProxy()) {
            return null;
        }

        $id = $request->header('X-Request-Id');

        return is_string($id) && preg_match('/^[A-Za-z0-9-]{8,64}$/', $id) ? $id : null;
    }
}
