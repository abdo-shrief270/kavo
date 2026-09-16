<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Platform\Analytics\Console\EnsureAnalyticsPartitions;
use App\Modules\Platform\Analytics\Console\FlushAnalyticsBuffer;
use App\Modules\Platform\Analytics\Services\BufferedAnalyticsIngestor;
use App\Modules\Platform\Entitlements\Console\FlushUsageCounters;
use App\Modules\Platform\Entitlements\Services\EntitlementService;
use App\Modules\Platform\Notifications\Gateways\BeOnGateway;
use App\Modules\Platform\Notifications\Gateways\LogWhatsAppGateway;
use App\Shared\Contracts\AnalyticsIngestor;
use App\Shared\Contracts\Entitlements;
use App\Shared\Contracts\WhatsAppGateway;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantDatabaseSession;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestTerminated;

final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped, not singleton: under Octane the container outlives the
        // request, and a singleton here would leak one tenant into the next.
        $this->app->scoped(TenantContext::class, fn () => new TenantContext);
        $this->app->scoped(TenantDatabaseSession::class);

        $this->app->singleton(Entitlements::class, EntitlementService::class);
        $this->app->singleton(AnalyticsIngestor::class, BufferedAnalyticsIngestor::class);

        $this->bindWhatsAppGateway();
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

    private function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            FlushUsageCounters::class,
            FlushAnalyticsBuffer::class,
            EnsureAnalyticsPartitions::class,
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
        });
    }

    /**
     * Queue workers are long-lived for the same reason. A job that inherits
     * the previous job's tenant would write rows under the wrong owner.
     */
    private function resetTenantStateBetweenJobs(): void
    {
        $reset = function (): void {
            app(TenantDatabaseSession::class)->clear();
            app(TenantContext::class)->forget();
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
                'route' => request()?->route()?->getName() ?? request()?->path(),
            ]);
        });
    }
}
