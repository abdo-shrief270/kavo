<?php

/*
 | Cross-origin access to this API.
 |
 | Published rather than left on the framework default, because that default is
 | `allowed_origins: ['*']` with `supports_credentials: false` — and this API is
 | reached by three first-party SPAs on their own origins using Sanctum's cookie
 | session. A browser refuses a credentialed request whose response says
 | `Access-Control-Allow-Origin: *`, so *every* dashboard call was blocked
 | before it left the browser. Nothing in the build, the typecheck or the test
 | suite sees this: it is a rule the browser enforces and only a browser can
 | catch.
 |
 | The wildcard is not merely broken here, it is the wrong answer. Allowing any
 | origin to make credentialed requests would let any page on the internet act
 | as a signed-in merchant. The list below is closed on purpose.
 */
return [
    // Sanctum's cookie handshake needs to be reachable too, and Echo
    // authorises private channels through /broadcasting/auth.
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    /*
     | The three first-party origins, plus anything a deployment adds. Read
     | from env rather than through config() because a config file cannot rely
     | on another config file having been loaded yet.
     */
    'allowed_origins' => array_values(array_unique(array_filter(array_merge(
        [
            env('FRONTEND_DASHBOARD_URL', 'http://localhost:5173'),
            env('FRONTEND_ADMIN_URL', 'http://localhost:5174'),
            env('FRONTEND_STOREFRONT_URL', 'http://localhost:3000'),
        ],
        array_map('trim', explode(',', (string) env('CORS_EXTRA_ORIGINS', ''))),
    )))),

    /*
     | Tenant storefronts on the platform's own apex. A shop's pages are
     | server-rendered and call the API server-side, where CORS does not apply,
     | but its browser bundle opens a socket and authorises private channels.
     |
     | Verified custom domains are deliberately not covered: a pattern cannot
     | express "whatever is in the domains table", and widening this to match
     | them would mean trusting a list this file cannot see.
     */
    'allowed_origins_patterns' => [
        '#^https?://[a-z0-9-]+\.'.preg_quote((string) env('KAVO_ROOT_DOMAIN', 'kavo.test'), '#').'(:\d+)?$#i',
    ],

    /*
     | Named rather than '*'. The spec forbids a wildcard here on a credentialed
     | request, and listing them documents what this API actually reads.
     */
    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-XSRF-TOKEN',
        // Which workspace the caller is acting in. Checked against their
        // memberships server side — naming a tenant is a request, not a grant.
        'X-Tenant',
        // A retried checkout must not become two orders.
        'Idempotency-Key',
    ],

    'exposed_headers' => [],

    // An hour, so a preflight is not paid on every request.
    'max_age' => 3600,

    /*
     | The whole point. Without this the browser sends no cookie, Sanctum sees
     | no session, and every request is unauthenticated.
     */
    'supports_credentials' => true,
];
