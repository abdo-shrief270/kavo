<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Platform\Identity\Models\Tenant;
use App\Modules\Platform\Media\Models\Media;
use App\Shared\Tenancy\TenantDatabaseSession;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Proves the second isolation layer.
 *
 * The Eloquent global scope is convention and can be bypassed — by a raw
 * query, a missing trait, or an explicit withoutGlobalScopes(). These tests
 * do exactly that, deliberately, and assert isolation still holds. If any of
 * them start returning another tenant's rows, the policies are off and the
 * application is one forgotten scope away from a data leak.
 */
final class RowLevelSecurityTest extends TestCase
{
    private Tenant $alpha;

    private Tenant $beta;

    private int $betaMediaId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = Tenant::factory()->create();
        $this->beta = Tenant::factory()->create();

        $this->betaMediaId = $this->asTenant($this->beta, fn (): int => Media::factory()->create()->getKey());
        $this->asTenant($this->alpha, fn () => Media::factory()->create());
    }

    #[Test]
    public function the_application_role_cannot_bypass_policies(): void
    {
        $role = DB::selectOne('SELECT current_user AS role, rolbypassrls, rolsuper FROM pg_roles WHERE rolname = current_user');

        // If either of these is ever true, every other test in this file is
        // meaningless — RLS would simply not apply to the application.
        $this->assertFalse((bool) $role->rolbypassrls, 'The application DB role holds BYPASSRLS, which disables row-level security.');
        $this->assertFalse((bool) $role->rolsuper, 'The application DB role is a superuser, which disables row-level security.');
    }

    #[Test]
    public function policies_are_enabled_and_forced_on_tenant_tables(): void
    {
        $table = DB::selectOne("SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = 'media'");

        $this->assertTrue((bool) $table->relrowsecurity, 'RLS is not enabled on media.');
        $this->assertTrue((bool) $table->relforcerowsecurity, 'RLS is not FORCEd on media, so a table owner would bypass it.');
    }

    #[Test]
    public function bypassing_the_global_scope_still_cannot_read_another_tenant(): void
    {
        $this->actingAsTenant($this->alpha);

        $rows = Media::query()->withoutGlobalScopes()->get();

        $this->assertCount(1, $rows, 'withoutGlobalScopes() exposed rows that RLS should have hidden.');
        $this->assertSame($this->alpha->getKey(), $rows->first()->tenant_id);
    }

    #[Test]
    public function a_raw_query_still_cannot_read_another_tenant(): void
    {
        $this->actingAsTenant($this->alpha);

        $ids = DB::table('media')->pluck('tenant_id')->unique()->values()->all();

        $this->assertSame([$this->alpha->getKey()], $ids, 'A raw query bypassed tenant isolation.');
    }

    #[Test]
    public function an_explicit_lookup_of_another_tenants_row_returns_nothing(): void
    {
        $this->actingAsTenant($this->alpha);

        $this->assertNull(Media::query()->withoutGlobalScopes()->find($this->betaMediaId));
        $this->assertNull(DB::table('media')->where('id', $this->betaMediaId)->first());
    }

    /**
     * The failure mode that matters most under Octane: a pooled connection
     * whose GUC was never set, or was cleared and not re-bound. It must
     * return nothing rather than everything.
     */
    #[Test]
    public function a_connection_with_no_tenant_bound_reads_nothing(): void
    {
        app(TenantDatabaseSession::class)->clear();

        $this->assertSame(0, DB::table('media')->count(), 'A connection with no tenant bound could read rows — RLS is failing open.');
    }

    #[Test]
    public function writes_into_another_tenant_are_rejected(): void
    {
        $this->actingAsTenant($this->alpha);

        $this->expectExceptionMessageMatches('/row-level security/i');

        DB::table('media')->insert([
            'tenant_id' => $this->beta->getKey(),
            'disk' => 'public',
            'path' => 'x.jpg',
            'filename' => 'x.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 1,
            'meta' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function updates_cannot_reassign_a_row_to_another_tenant(): void
    {
        $this->actingAsTenant($this->alpha);

        $this->expectExceptionMessageMatches('/row-level security/i');

        DB::table('media')->update(['tenant_id' => $this->beta->getKey()]);
    }

    #[Test]
    public function deletes_cannot_reach_another_tenants_rows(): void
    {
        $this->actingAsTenant($this->alpha);

        $deleted = DB::table('media')->where('id', $this->betaMediaId)->delete();

        $this->assertSame(0, $deleted, 'A delete reached across the tenant boundary.');

        // And the row genuinely survived — checked from Beta's own context
        // rather than the owner connection, which sits outside this test's
        // transaction and would report the pre-test state.
        $this->actingAsTenant($this->beta);

        $this->assertSame(1, DB::table('media')->where('id', $this->betaMediaId)->count());
    }

    #[Test]
    public function the_partitioned_analytics_table_is_also_protected(): void
    {
        // Inserted through the application connection inside each tenant's
        // own context, so the rows stay inside this test's transaction. The
        // owner connection would write outside it and leak into later tests.
        foreach ([$this->alpha, $this->beta] as $tenant) {
            $this->asTenant($tenant, fn () => DB::table('analytics_events')->insert([
                'tenant_id' => $tenant->getKey(),
                'event_name' => 'test.event',
                'properties' => '{}',
                'occurred_at' => now(),
                'created_at' => now(),
            ]));
        }

        $this->actingAsTenant($this->alpha);

        $ids = DB::table('analytics_events')->pluck('tenant_id')->unique()->values()->all();

        $this->assertSame([$this->alpha->getKey()], $ids, 'RLS is not applying to the partitioned analytics table.');
    }
}
