<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Platform\Billing\Ledger\Models\LedgerEntry;
use App\Modules\Platform\Identity\Models\Tenant;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Whose money am I holding?" — the screen the platform actually works from.
 *
 * The one ledger query that legitimately spans tenants. Row-level security
 * matches nothing with no tenant bound, correctly, so a per-tenant total
 * cannot be read from platform scope at all; this goes through the schema
 * owner instead, the same shape as the settlement lookup in
 * ApplyGatewaySettlement, and every read is audited.
 *
 * The fixtures are committed rather than transactional. A row created inside
 * the test's own transaction is invisible to the owner connection, so a
 * transactional fixture would leave the code under test reading an empty
 * table and the test passing for the wrong reason — which is exactly what the
 * first draft of it did.
 */
final class PlatformBalancesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ends the transaction RefreshDatabase opened; tearDown truncates
        // instead. See the class docblock.
        DB::rollBack();
    }

    protected function tearDown(): void
    {
        DB::connection(config('kaabosh.tenancy.owner_connection'))->unprepared(
            'truncate ledger_entries, payouts, tenant_user, users, tenants restart identity cascade',
        );

        parent::tearDown();
    }

    #[Test]
    public function the_platform_sees_every_merchant_it_owes(): void
    {
        $alpha = Tenant::factory()->create();
        $beta = Tenant::factory()->create();

        $this->asTenant($alpha, fn () => LedgerEntry::factory()->of(99_000)->create());
        $this->asTenant($beta, fn () => LedgerEntry::factory()->of(250_000)->create());

        $this->forgetTenant();

        $response = $this->actingAs(User::factory()->create(['is_platform_admin' => true]))
            ->getJson('/api/admin/balances')
            ->assertOk();

        $balances = collect($response->json('balances'))->keyBy('tenant_id');

        $this->assertSame(99_000, $balances[$alpha->getKey()]['balance_cents']);
        $this->assertSame(250_000, $balances[$beta->getKey()]['balance_cents']);

        // Reading across tenants is the one privileged thing this console
        // does, so it is on the platform trail.
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.balances_viewed']);
    }

    /** A merchant is not a platform administrator. */
    #[Test]
    public function a_merchant_cannot_reach_the_payout_console(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

        $this->actingAs($owner)->getJson('/api/admin/balances')->assertStatus(403);
    }
}
