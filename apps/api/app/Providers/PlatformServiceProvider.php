<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Platform\Analytics\Console\EnsureAnalyticsPartitions;
use App\Modules\Platform\Analytics\Console\FlushAnalyticsBuffer;
use App\Modules\Platform\Analytics\Services\BufferedAnalyticsIngestor;
use App\Modules\Platform\Billing\Listeners\ApplyGatewaySettlement;
use App\Modules\Platform\Billing\Payments\Console\ExpireOverduePayments;
use App\Modules\Platform\Billing\Payments\Gateways\FawryGateway;
use App\Modules\Platform\Billing\Payments\Gateways\PaymobGateway;
use App\Modules\Platform\Billing\Payments\PaymentGatewayManager;
use App\Modules\Platform\Entitlements\Console\FlushUsageCounters;
use App\Modules\Platform\Entitlements\Services\EntitlementService;
use App\Modules\Platform\Notifications\Channels\TenantDatabaseChannel;
use App\Modules\Platform\Notifications\Channels\WhatsAppChannel;
use App\Modules\Platform\Notifications\Gateways\BeOnGateway;
use App\Modules\Platform\Notifications\Gateways\LogWhatsAppGateway;
use App\Modules\Platform\Notifications\Listeners\SendQuotaThresholdAlert;
use App\Modules\Platform\Observability\Console\SlowQueries;
use App\Modules\Platform\Observability\Services\RequestContext;
use App\Modules\Platform\Webhooks\Services\WebhookDispatcher;
use App\Shared\Contracts\AnalyticsIngestor;
use App\Shared\Contracts\Entitlements;
use App\Shared\Contracts\OutboundEvents;
use App\Shared\Contracts\ResolvesHosts;
use App\Shared\Contracts\WhatsAppGateway;
use App\Shared\Events\InboundWebhookReceived;
use App\Shared\Events\QuotaThresholdReached;
use App\Shared\Http\SystemHostResolver;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantDatabaseSession;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestTerminated;

final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->trustTheProxiesInFront();

        // scoped, not singleton: under Octane the container outlives the
        // request, and a singleton here would leak one tenant into the next.
        $this->app->scoped(TenantContext::class, fn () => new TenantContext);
        $this->app->scoped(TenantDatabaseSession::class);

        // Scoped for the same reason TenantContext is: under Octane a shared
        // correlation id would splice two requests into one trace.
        $this->app->scoped(RequestContext::class, fn () => new RequestContext);

        $this->app->singleton(Entitlements::class, EntitlementService::class);
        $this->app->singleton(AnalyticsIngestor::class, BufferedAnalyticsIngestor::class);

        // How a vertical says something happened without knowing that the
        // platform delivers it over HTTP with signatures and backoff.
        $this->app->singleton(OutboundEvents::class, WebhookDispatcher::class);

        // Behind the container so tests can hand the destination guard
        // answers that public DNS will never give them.
        $this->app->singleton(ResolvesHosts::class, SystemHostResolver::class);

        $this->bindWhatsAppGateway();
        $this->bindPaymentGateways();
    }

    /**
     * The API never faces a browser directly.
     *
     * Caddy terminates TLS in front of it, and the storefront's server calls
     * it over the loopback during SSR. In both cases the hostname the visitor
     * actually asked for survives only in X-Forwarded-Host — and a storefront
     * tenant is identified by hostname, so without this every storefront
     * request resolves no tenant and renders an empty shop.
     *
     * Set here rather than in bootstrap/app.php because the middleware
     * closure there runs before configuration is loaded.
     *
     * Scoped to configured proxies and never '*': a client that can set its
     * own X-Forwarded-Host can claim to be any tenant.
     */
    private function trustTheProxiesInFront(): void
    {
        TrustProxies::at(config('kavo.trusted_proxies', []));
        TrustProxies::withHeaders(
            HttpRequest::HEADER_X_FORWARDED_FOR
            | HttpRequest::HEADER_X_FORWARDED_HOST
            | HttpRequest::HEADER_X_FORWARDED_PORT
            | HttpRequest::HEADER_X_FORWARDED_PROTO
        );
    }

    /**
     * Gateways are resolved by rail through the manager, so no caller names
     * Paymob or Fawry directly. Credentials are read here rather than in the
     * gateways so config:cache keeps working.
     */
    private function bindPaymentGateways(): void
    {
        $this->app->singleton(PaymentGatewayManager::class);

        $this->app->bind(PaymobGateway::class, fn ($app) => new PaymobGateway(
            $app->make(\Illuminate\Http\Client\Factory::class),
            (string) config('services.paymob.base_url'),
            (string) config('services.paymob.api_key'),
            (string) config('services.paymob.integration_id'),
            (string) config('services.paymob.iframe_id'),
        ));

        $this->app->bind(FawryGateway::class, fn ($app) => new FawryGateway(
            $app->make(\Illuminate\Http\Client\Factory::class),
            (string) config('services.fawry.base_url'),
            (string) config('services.fawry.merchant_code'),
            (string) config('services.fawry.security_key'),
            (int) config('kavo.payments.reference_expiry_hours', 72),
        ));
    }

    /**
     * Bound to the interface, never to BeOn directly. Moving to the Meta
     * Cloud API for cost or control, or offering tenants their own number,
     * should cost one binding — not a rewrite of every caller.
     */
    private function bindWhatsAppGateway(): void
    {
        $this->app->singleton(WhatsAppGateway::class, function ($app): WhatsAppGateway {
            $apiKey = (string) config('services.beon.api_key');

            // No credentials configured: log sends instead of failing. Keeps
            // the whole path — template lookup, approval check, delivery
            // logging — exercised locally and in tests.
            if ($apiKey === '') {
                return new LogWhatsAppGateway;
            }

            return new BeOnGateway(
                $app->make(\Illuminate\Http\Client\Factory::class),
                (string) config('services.beon.base_url'),
                $apiKey,
                (string) config('services.beon.webhook_secret'),
            );
        });
    }

    public function boot(): void
    {
        $this->resetTenantStateBetweenRequests();
        $this->resetTenantStateBetweenJobs();
        $this->logSlowQueries();
        $this->resolveModuleFactories();
        $this->registerNotificationChannels();
        $this->registerListeners();
        $this->registerCommands();
        $this->registerSchedule();
    }

    /**
     * Models live under app/Modules/..., so Laravel's default guess maps them
     * to factory namespaces that mirror that depth. Flattening to the class
     * basename keeps every factory in database/factories, where the framework
     * and every contributor expects to find them.
     */
    private function resolveModuleFactories(): void
    {
        Factory::guessFactoryNamesUsing(
            static fn (string $model): string => 'Database\\Factories\\'.class_basename($model).'Factory'
        );
    }

    /**
     * Registers the WhatsApp channel under the name notifications use in
     * their via() list.
     */
    private function registerNotificationChannels(): void
    {
        Notification::extend('whatsapp', fn ($app) => $app->make(WhatsAppChannel::class));

        // Replaces the framework channel so in-app notifications carry a
        // tenant_id and survive their own RLS policy.
        Notification::extend('database', fn ($app) => $app->make(TenantDatabaseChannel::class));
    }

    private function registerListeners(): void
    {
        Event::listen(QuotaThresholdReached::class, SendQuotaThresholdAlert::class);

        // Settlement is the only way an offline payment ever becomes paid, so
        // every payment provider's callbacks route into the same handler.
        // One registration, for every inbound callback. The listener decides
        // whether the provider is one it settles payments for; a matrix of
        // guessed provider event-type strings decided that by accident, and
        // Paymob's real "TRANSACTION" did not match the "transaction" in it.
        Event::listen(InboundWebhookReceived::class, [ApplyGatewaySettlement::class, 'handle']);
    }

    private function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            FlushUsageCounters::class,
            FlushAnalyticsBuffer::class,
            EnsureAnalyticsPartitions::class,
            SlowQueries::class,
            ExpireOverduePayments::class,
        ]);
    }

    private function registerSchedule(): void
    {
        $this->app->booted(function (): void {
            $schedule = $this->app->make(Schedule::class);

            $schedule->command('kavo:flush-usage')->everyMinute()->withoutOverlapping();
            $schedule->command('kavo:flush-analytics')->everyMinute()->withoutOverlapping();

            // Keeps partition runway ahead of ingestion. The DEFAULT
            // partition means a missed run costs pruning, not data.
            $schedule->command('kavo:analytics-partitions')->monthlyOn(1, '00:10');

            // An unpaid reference left open holds its reservation forever.
            $schedule->command('kavo:expire-payments')->hourly()->withoutOverlapping();
        });
    }

    /**
     * Octane keeps workers alive between requests, so tenant state has to be
     * torn down explicitly. Missing this is a cross-tenant data leak, not a
     * memory problem.
     */
    private function resetTenantStateBetweenRequests(): void
    {
        if (! class_exists(RequestTerminated::class)) {
            return;
        }

        Event::listen(RequestTerminated::class, function (): void {
            app(TenantDatabaseSession::class)->clear();
            app(TenantContext::class)->forget();
            app(RequestContext::class)->forget();
        });
    }

    /**
     * Queue workers are long-lived for the same reason, so a job must not
     * inherit the tenant the previous job happened to leave behind.
     *
     * The `sync` connection is deliberately excluded. A sync job runs inside
     * whatever dispatched it — a web request that has already bound a tenant —
     * and tearing that state down mid-request strands the rest of it with no
     * tenant. Postgres then aborts the surrounding transaction and every later
     * statement fails, which presents as an unrelated 500 far from the cause.
     * The dispatcher owns that state; only a real worker should clear it.
     */
    private function resetTenantStateBetweenJobs(): void
    {
        $reset = function (JobProcessed|JobFailed $event): void {
            if ($event->connectionName === 'sync') {
                return;
            }

            app(TenantDatabaseSession::class)->clear();
            app(TenantContext::class)->forget();
            app(RequestContext::class)->forget();
        };

        Event::listen(JobProcessed::class, $reset);
        Event::listen(JobFailed::class, $reset);
    }

    /**
     * Ties a slow query back to which tenant on which route triggered it —
     * the detail that makes a slow-query log actionable in a shared-schema
     * multi-tenant database.
     */
    private function logSlowQueries(): void
    {
        $threshold = (int) config('kavo.slow_query_ms', 200);

        DB::listen(function ($query) use ($threshold): void {
            if ($query->time < $threshold) {
                return;
            }

            Log::channel('slow')->warning('Slow query', [
                'sql' => $query->sql,
                'time_ms' => $query->time,
                'connection' => $query->connectionName,
                'tenant_id' => app(TenantContext::class)->id(),
                'route' => request()->route()?->getName() ?? request()->path(),
            ]);
        });
    }
}
