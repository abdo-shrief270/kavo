<?php

declare(strict_types=1);

namespace Tests;

use App\Modules\Platform\Identity\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantDatabaseSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Redis;

abstract class TestCase extends BaseTestCase
{
    // Used here rather than in each test class on purpose: a trait's method
    // beats an inherited one, so RefreshDatabase applied in a subclass would
    // silently win over the migrateFreshUsing() override below and run
    // migrations as the application role.
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Usage counters, quota-threshold markers and rate limiters live in
        // Redis, which no database transaction rolls back. Without this, one
        // test's consumption is the next test's starting balance.
        Redis::connection()->flushdb();
    }

    /**
     * Migrations run as the schema owner, never as the application role.
     *
     * This mirrors production exactly: kavo_app has no DDL rights and cannot
     * bypass RLS, so if the suite could migrate as kavo_app the tests would
     * be running with privileges the real application never has.
     *
     * @return array<string, string>
     */
    protected function migrateFreshUsing()
    {
        return [
            '--database' => 'pgsql_owner',
            '--drop-views' => false,
            '--drop-types' => false,
        ];
    }

    /**
     * Enter a tenant's context the same way a request does: set the context
     * object AND bind the Postgres GUC. Binding only one of the two is the
     * bug this whole design exists to catch, so tests never do it by halves.
     */
    protected function actingAsTenant(Tenant $tenant): static
    {
        app(TenantContext::class)->set($tenant);
        app(TenantDatabaseSession::class)->bind($tenant->getKey());

        return $this;
    }

    protected function forgetTenant(): static
    {
        app(TenantDatabaseSession::class)->clear();
        app(TenantContext::class)->forget();

        return $this;
    }

    /**
     * Create a row belonging to another tenant.
     *
     * Writes go through the owner connection because RLS would (correctly)
     * refuse to let the app role write outside its own tenant — which is the
     * property under test, so the fixture must not depend on it.
     */
    protected function asTenant(Tenant $tenant, callable $callback): mixed
    {
        return app(TenantContext::class)->runAs($tenant, function () use ($tenant, $callback) {
            app(TenantDatabaseSession::class)->bind($tenant->getKey());

            try {
                return $callback();
            } finally {
                app(TenantDatabaseSession::class)->clear();
            }
        });
    }
}
