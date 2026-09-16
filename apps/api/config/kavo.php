<?php

use App\Modules\Platform\Billing\Payments\Gateways\FakeGateway;
use App\Modules\Platform\Billing\Payments\Gateways\FawryGateway;
use App\Modules\Platform\Billing\Payments\Gateways\PaymobGateway;
use App\Modules\Platform\Webhooks\Verifiers\BeOnWebhookVerifier;
use App\Modules\Platform\Webhooks\Verifiers\FawryWebhookVerifier;
use App\Modules\Platform\Webhooks\Verifiers\PaymobWebhookVerifier;

return [
    /*
     | The apex domain tenants get subdomains under, e.g. acme.kavo.test
     */
    'root_domain' => env('KAVO_ROOT_DOMAIN', 'kavo.test'),

    /*
     | Shared secret Caddy presents to the on-demand TLS ask endpoint.
     */
    'tls_ask_token' => env('KAVO_TLS_ASK_TOKEN'),

    'frontend' => [
        'dashboard' => env('FRONTEND_DASHBOARD_URL', 'http://localhost:5173'),
        'admin' => env('FRONTEND_ADMIN_URL', 'http://localhost:5174'),
        'storefront' => env('FRONTEND_STOREFRONT_URL', 'http://localhost:3000'),
    ],

    'tenancy' => [
        /*
         | Postgres GUC that RLS policies read. Set per request, cleared on
         | request termination so a pooled connection never carries it over.
         */
        'guc' => 'app.tenant_id',

        /*
         | Connection owning the schema. Migrations run here; the application
         | never does, so RLS is never bypassed by table ownership.
         */
        'owner_connection' => 'pgsql_owner',
    ],

    'slow_query_ms' => (int) env('KAVO_SLOW_QUERY_MS', 200),

    'quotas' => [
        /*
         | Usage counters buffer in Redis and flush on this cadence. Metrics
         | listed as accuracy-critical read through to Postgres instead.
         */
        'flush_interval_seconds' => 60,
        'accuracy_critical' => ['orders'],
        'alert_thresholds' => [80, 100],
    ],

    'payments' => [
        /*
         | Which gateway serves which rail. Egypt needs two providers, not
         | one: Paymob has the better API and covers cards, wallets and
         | Apple/Google Pay, while Fawry is how a large share of the market
         | actually pays. A rail with no gateway is simply not offered.
         */
        'rails' => [
            'card' => env('KAVO_CARD_GATEWAY', 'fake'),
            'wallet' => env('KAVO_WALLET_GATEWAY', 'fake'),
            'reference' => env('KAVO_REFERENCE_GATEWAY', 'fake'),
            'cod' => env('KAVO_COD_GATEWAY', 'fake'),
        ],

        'gateways' => [
            'fake' => FakeGateway::class,
            'paymob' => PaymobGateway::class,
            'fawry' => FawryGateway::class,
        ],

        /*
         | How long a customer has to pay an issued reference before the
         | order is released. Fawry's own default is 72 hours.
         */
        'reference_expiry_hours' => (int) env('KAVO_REFERENCE_EXPIRY_HOURS', 72),
    ],

    'webhooks' => [
        /*
         | One verifier per inbound provider. A provider with no entry here is
         | rejected with a 404 rather than accepted unverified.
         */
        'verifiers' => [
            'beon' => BeOnWebhookVerifier::class,
            'paymob' => PaymobWebhookVerifier::class,
            // Without this entry /webhooks/fawry 404s, and a reference
            // payment has no other way to ever become paid.
            'fawry' => FawryWebhookVerifier::class,
        ],

        'outbound' => [
            'backoff' => [30, 120, 600, 3600, 21600],
            'disable_after_consecutive_failures' => 10,
        ],
        'inbound' => [
            'tolerance_seconds' => 300,
        ],
    ],
];
