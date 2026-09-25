<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The rule only a browser enforces.
 *
 * Three SPAs on their own origins reach this API with Sanctum's cookie
 * session, which makes every call a credentialed one. A browser refuses a
 * credentialed response that says `Access-Control-Allow-Origin: *`, and the
 * framework's default CORS config says exactly that — so every dashboard
 * request was blocked before it left the browser, while the build, the
 * typecheck, the test suite and CI all stayed green.
 *
 * These tests are the standing replacement for having noticed.
 */
final class CrossOriginTest extends TestCase
{
    private function dashboard(): string
    {
        return (string) config('kavo.frontend.dashboard');
    }

    #[Test]
    public function a_first_party_dashboard_may_make_credentialed_calls(): void
    {
        $response = $this->call('GET', '/api/me', server: ['HTTP_ORIGIN' => $this->dashboard()]);

        // The origin is echoed, never '*' — a browser rejects the wildcard
        // the moment credentials are involved.
        $this->assertSame($this->dashboard(), $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
    }

    /**
     * The handshake that has to happen before any write. If this one origin is
     * missed, every POST fails on a CSRF token the client could never fetch.
     */
    #[Test]
    public function the_csrf_handshake_is_reachable_cross_origin(): void
    {
        $response = $this->call('GET', '/sanctum/csrf-cookie', server: ['HTTP_ORIGIN' => $this->dashboard()]);

        $this->assertSame($this->dashboard(), $response->headers->get('Access-Control-Allow-Origin'));
    }

    /**
     * A preflight is what the browser actually sends first, and it must accept
     * the headers this API's own client sets — a header missing from the list
     * fails the request with no error the user can act on.
     */
    #[Test]
    public function a_preflight_accepts_the_headers_the_client_sends(): void
    {
        $response = $this->call('OPTIONS', '/api/products', server: [
            'HTTP_ORIGIN' => $this->dashboard(),
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-requested-with,x-xsrf-token,x-tenant,idempotency-key',
        ]);

        $response->assertNoContent(204);

        $allowed = strtolower((string) $response->headers->get('Access-Control-Allow-Headers'));

        foreach (['content-type', 'x-requested-with', 'x-xsrf-token', 'x-tenant', 'idempotency-key'] as $header) {
            $this->assertStringContainsString($header, $allowed, "The preflight refuses {$header}.");
        }

        $this->assertStringContainsString('PATCH', (string) $response->headers->get('Access-Control-Allow-Methods'));
    }

    /**
     * Allowing any origin to make credentialed requests would let any page on
     * the internet act as a signed-in merchant. The wildcard is not a
     * convenience here, it is the vulnerability.
     */
    #[Test]
    public function a_stranger_origin_is_not_allowed_to_act_as_the_merchant(): void
    {
        $response = $this->call('GET', '/api/me', server: ['HTTP_ORIGIN' => 'https://evil.test']);

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    /** A tenant storefront on the platform apex opens a socket from a browser. */
    #[Test]
    public function a_storefront_subdomain_is_allowed(): void
    {
        $origin = 'https://acme.'.config('kavo.root_domain');

        $response = $this->call('GET', '/api/storefront/config', server: ['HTTP_ORIGIN' => $origin]);

        $this->assertSame($origin, $response->headers->get('Access-Control-Allow-Origin'));
    }

    /** And a look-alike of it is not. */
    #[Test]
    public function a_lookalike_of_the_apex_is_not_allowed(): void
    {
        $response = $this->call('GET', '/api/me', server: [
            'HTTP_ORIGIN' => 'https://acme.'.config('kavo.root_domain').'.evil.test',
        ]);

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }
}
