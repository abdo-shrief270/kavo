<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domains\Http\Controllers;

use App\Modules\Platform\Identity\Services\TenantLocator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Caddy on-demand TLS gate.
 *
 * Caddy asks before issuing a certificate for an unknown hostname; a 200 here
 * means "issue", anything else means "refuse". This replaces the whole
 * Certbot/DNS-01 orchestration: no cron, no DNS API credentials, no
 * per-tenant server block.
 *
 * It must stay cheap and it must refuse by default — an endpoint that
 * approved everything would let anyone point a hostname at us and have us
 * request certificates on their behalf until we hit a rate limit.
 */
final class TlsAskController
{
    public function __invoke(Request $request, TenantLocator $locator): Response
    {
        $expected = (string) config('kavo.tls_ask_token');

        // Query string, because Caddy's `ask` sends a bare GET and offers no
        // way to add a header — a token expected in one would have rejected
        // every issuance, and custom domains would simply never get a
        // certificate. It is in the URL rather than being no check at all:
        // the endpoint listens on loopback, and the real gate is
        // hostnameIsIssuable below. The header is still accepted so the
        // endpoint can be probed by hand without putting the token in a
        // shell history.
        $presented = (string) ($request->query('token') ?? $request->header('X-Caddy-Token', ''));

        if ($expected === '' || ! hash_equals($expected, $presented)) {
            return response('', 403);
        }

        $hostname = mb_strtolower((string) $request->query('domain', ''));

        if ($hostname === '' || ! $locator->hostnameIsIssuable($hostname)) {
            return response('', 404);
        }

        return response('', 200);
    }
}
