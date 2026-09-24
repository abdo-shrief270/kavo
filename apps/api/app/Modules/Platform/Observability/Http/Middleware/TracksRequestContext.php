<?php

declare(strict_types=1);

namespace App\Modules\Platform\Observability\Http\Middleware;

use App\Modules\Platform\Observability\Services\ErrorContext;
use App\Modules\Platform\Observability\Services\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;
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
     * Accepted only from a caller that proves it is one of ours.
     *
     * Being behind a trusted proxy is not proof: Caddy forwards a browser's
     * headers unchanged, so trusting the hop would let any visitor choose an
     * id and collide with — or poison — someone else's trace. The storefront's
     * SSR server presents a shared secret instead, which is the difference
     * between "arrived through my proxy" and "was written by my code".
     *
     * With no token configured this returns null for everything, so the
     * failure mode is a fresh id per hop rather than a forgeable one.
     */
    private function inboundId(Request $request): ?string
    {
        if (! $this->fromKnownAddress($request) || ! $this->isFirstParty($request)) {
            return null;
        }

        $id = $request->header('X-Request-Id');

        return is_string($id) && preg_match('/^[A-Za-z0-9-]{8,64}$/', $id) ? $id : null;
    }

    /**
     * The peer address, checked directly rather than via isFromTrustedProxy().
     *
     * This middleware is prepended to the global stack so that everything
     * after it carries a correlation id — which puts it *before* the
     * middleware that establishes which proxies are trusted. isFromTrustedProxy()
     * therefore always answered false here, which is why an inbound id was
     * never once accepted and one page render always produced two unrelated
     * sets of logs.
     */
    private function fromKnownAddress(Request $request): bool
    {
        $proxies = (array) config('kavo.trusted_proxies', []);
        $ip = (string) $request->server->get('REMOTE_ADDR', '');

        return $proxies !== [] && $ip !== '' && IpUtils::checkIp($ip, $proxies);
    }

    private function isFirstParty(Request $request): bool
    {
        $expected = (string) config('kavo.internal_token');

        return $expected !== '' && hash_equals($expected, (string) $request->header('X-Internal-Token', ''));
    }
}
