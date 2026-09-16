<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Second isolation layer.
 *
 * The Eloquent global scope is the first, and it is made of convention: it is
 * bypassed by a raw query, a model missing the trait, or an explicit
 * withoutGlobalScopes(). These policies make a forgotten scope non-fatal.
 *
 * Three things have to hold for this to be real rather than decorative:
 *
 *  1. The application role must not own these tables. Owners bypass RLS
 *     unless FORCE ROW LEVEL SECURITY is set — it is set below, and the roles
 *     are split anyway so migrations and requests use different identities.
 *  2. The application role must not hold BYPASSRLS. kavo_app is created
 *     NOBYPASSRLS and is not a superuser. The migration role kavo_owner does
 *     hold BYPASSRLS, deliberately: FORCE applies policies to the owner too,
 *     which would otherwise block legitimate expand/contract backfills across
 *     tenants. See deploy/sql/01-provision-roles.sql.
 *  3. The GUC must be cleared when a request or job ends, or a pooled
 *     connection carries one tenant's id into the next tenant's work.
 *     PlatformServiceProvider does that on RequestTerminated and after jobs.
 *
 * Note on what is deliberately NOT covered. Three tables form the resolution
 * and authorisation layer, and are necessarily read before any tenant is
 * bound — a policy on them would deadlock every request:
 *
 *   tenants      the thing being scoped to, looked up by hostname or slug.
 *   domains      maps a hostname to a tenant, read before the GUC is set.
 *                Reached only through TenantLocator, the single audited
 *                pre-tenant read path.
 *   tenant_user  membership. This is what ResolveTenant and every channel
 *                authorisation callback consult to decide whether a user may
 *                act as a tenant at all, so it cannot itself require a bound
 *                tenant. Every query against it is scoped by the
 *                authenticated user_id instead, which is the meaningful
 *                boundary for a membership record.
 *
 * These three carry no tenant payload — no orders, media, invoices or
 * messages. Everything that does is covered below.
 */
return new class extends Migration
{
    /** Tables carrying tenant_id, in dependency order. */
    private const TENANT_TABLES = [
        'subscriptions',
        'invoices',
        'invoice_lines',
        'coupon_redemptions',
        'payment_methods',
        'usage_counters',
        'tenant_theme_settings',
        'media',
        'notifications',
        'notification_preferences',
        'notification_deliveries',
        'webhook_subscriptions',
        'webhook_deliveries',
        'audit_logs',
        'analytics_events',
    ];

    public function up(): void
    {
        $appRole = $this->appRole();

        $this->grantApplicationPrivileges($appRole);

        foreach (self::TENANT_TABLES as $table) {
            $this->protect($table);
        }
    }

    public function down(): void
    {
        foreach (self::TENANT_TABLES as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }

    private function protect(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");

        $guc = config('kavo.tenancy.guc');

        // NULLIF(..., '') means an unset or cleared GUC yields NULL, and
        // `tenant_id = NULL` matches nothing. A connection that lost its
        // tenant binding therefore reads zero rows rather than every row.
        DB::statement(<<<SQL
            CREATE POLICY tenant_isolation ON {$table}
            USING (tenant_id = NULLIF(current_setting('{$guc}', true), '')::bigint)
            WITH CHECK (tenant_id = NULLIF(current_setting('{$guc}', true), '')::bigint)
        SQL);
    }

    private function grantApplicationPrivileges(string $appRole): void
    {
        DB::statement("GRANT USAGE ON SCHEMA public TO {$appRole}");
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO {$appRole}");
        DB::statement("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO {$appRole}");

        // Tables created by later migrations inherit the same grants, so a
        // new module does not silently ship without application access.
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$appRole}");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO {$appRole}");
    }

    private function appRole(): string
    {
        $role = (string) config('database.connections.pgsql.username');

        // Interpolated into DDL, which cannot be parameterised — so it is
        // validated rather than trusted.
        if (! preg_match('/^[a-z_][a-z0-9_]*$/i', $role)) {
            throw new RuntimeException("Refusing to build grants for unsafe role name: {$role}");
        }

        return $role;
    }
};
