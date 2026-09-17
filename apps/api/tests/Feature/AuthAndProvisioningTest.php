<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Platform\Billing\Models\Plan;
use App\Modules\Platform\Billing\Models\PlanFeature;
use App\Modules\Platform\Identity\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Covers the Phase 0 exit gate up to quota enforcement: sign up, get a tenant
 * and a plan, read usage, and be refused another tenant's workspace.
 *
 * These exist because the first three defects found by actually running the
 * API were all in this path and none of them were reachable from the unit
 * level — an undefined auth guard and a double-registered session stack look
 * fine until a real request arrives.
 */
final class AuthAndProvisioningTest extends TestCase
{
    private function seedPlan(): Plan
    {
        $plan = Plan::factory()->create(['code' => 'starter', 'trial_days' => 14]);

        PlanFeature::create(['plan_id' => $plan->id, 'feature_key' => 'orders', 'limit_value' => 100, 'overage_behavior' => 'block']);

        return $plan;
    }

    #[Test]
    public function the_sanctum_guard_is_configured(): void
    {
        // Without this the entire API returns 500 on the first authenticated
        // request, which no amount of service-level testing would reveal.
        $this->assertNotNull(config('auth.guards.sanctum'), 'The sanctum guard is not defined; every API route will fail.');
        $this->assertSame('sanctum', config('auth.guards.sanctum.driver'));
    }

    /**
     * The dashboard's workspace switcher is built from this, and the role it
     * shows decides which controls it renders. It was never asserted.
     */
    #[Test]
    public function me_lists_the_workspaces_a_user_belongs_to_with_their_role(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Ada Atelier']);
        $other = Tenant::factory()->create();
        $user = User::factory()->create();

        $tenant->users()->attach($user, ['role' => 'owner', 'joined_at' => now()]);

        $response = $this->actingAs($user)->getJson('/api/me')->assertOk();

        // Exactly one: a workspace the user has no membership in is not theirs
        // to see, however it was created.
        $response->assertJsonCount(1, 'user.tenants')
            ->assertJsonPath('user.tenants.0.slug', $tenant->slug)
            ->assertJsonPath('user.tenants.0.name', 'Ada Atelier')
            ->assertJsonPath('user.tenants.0.product', $tenant->product->value)
            ->assertJsonPath('user.tenants.0.role', 'owner');

        $this->assertNotContains($other->slug, array_column($response->json('user.tenants'), 'slug'));
    }

    /**
     * A soft-deleted workspace leaves its memberships behind. Listing one
     * would put a workspace in the switcher that cannot be opened.
     */
    #[Test]
    public function me_omits_a_workspace_that_has_been_deleted(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $tenant->users()->attach($user, ['role' => 'owner', 'joined_at' => now()]);

        $tenant->delete();

        $this->actingAs($user)->getJson('/api/me')->assertOk()->assertJsonCount(0, 'user.tenants');
    }

    #[Test]
    public function signing_up_provisions_a_tenant_with_a_plan_and_a_subscription(): void
    {
        $this->seedPlan();

        $response = $this->postJson('/api/register', [
            'name' => 'Ada',
            'email' => 'ada@kavo.test',
            'password' => 'Str0ng-Passw0rd!',
            'workspace' => 'Ada Atelier',
            'product' => 'fashion',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('tenant.name', 'Ada Atelier')
            ->assertJsonPath('user.email', 'ada@kavo.test')
            // Must be false, never null: both SPAs branch on this to decide
            // which console a user belongs in.
            ->assertJsonPath('user.is_platform_admin', false);

        $tenant = Tenant::query()->where('slug', $response->json('tenant.slug'))->firstOrFail();

        $this->assertSame('fashion', $tenant->product->value);
        $this->assertNotNull($tenant->trial_ends_at);

        // The owner is a member, and the subscription exists. A tenant with
        // no membership is unreachable by anyone, which is worse than a
        // failed signup.
        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenant->id,
            'role' => 'owner',
        ]);

        $this->actingAsTenant($tenant);
        $this->assertDatabaseHas('subscriptions', ['tenant_id' => $tenant->id, 'status' => 'trialing']);
    }

    #[Test]
    public function slugs_do_not_collide(): void
    {
        $this->seedPlan();

        foreach (['a@kavo.test', 'b@kavo.test'] as $email) {
            $this->postJson('/api/register', [
                'name' => 'Owner',
                'email' => $email,
                'password' => 'Str0ng-Passw0rd!',
                'workspace' => 'Same Name',
                'product' => 'fashion',
            ])->assertStatus(201);

            // Registering signs the new owner in, and the register route is
            // guest-only — so the second signup needs a clean session, the
            // same as a second person in a different browser.
            Auth::logout();
            $this->flushSession();
            $this->app['auth']->forgetGuards();
        }

        $this->assertSame(2, Tenant::query()->where('name', 'Same Name')->count());
        $this->assertSame(2, Tenant::query()->where('name', 'Same Name')->distinct()->count('slug'));
    }

    #[Test]
    public function login_does_not_reveal_whether_an_account_exists(): void
    {
        User::factory()->create(['email' => 'real@kavo.test', 'password' => 'Str0ng-Passw0rd!']);

        $wrongPassword = $this->postJson('/api/login', ['email' => 'real@kavo.test', 'password' => 'nope']);
        $noSuchUser = $this->postJson('/api/login', ['email' => 'ghost@kavo.test', 'password' => 'nope']);

        $wrongPassword->assertStatus(422);
        $noSuchUser->assertStatus(422);

        // Identical messages, or the endpoint is an account enumerator.
        $this->assertSame(
            $wrongPassword->json('errors.email'),
            $noSuchUser->json('errors.email'),
        );
    }

    #[Test]
    public function the_tenant_endpoint_reports_usage_for_the_resolved_tenant(): void
    {
        $plan = $this->seedPlan();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        $user = User::factory()->create();
        $tenant->users()->attach($user, ['role' => 'owner', 'joined_at' => now()]);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/tenant');

        $response->assertOk()
            ->assertJsonPath('tenant.slug', $tenant->slug)
            ->assertJsonStructure(['usage' => [['metric', 'used', 'remaining']]]);

        $orders = collect($response->json('usage'))->firstWhere('metric', 'orders');

        $this->assertSame(0, $orders['used']);
        $this->assertSame(100, $orders['remaining']);
    }

    /**
     * Naming a tenant is a request, not a grant. Without the membership check
     * in ResolveTenant, any authenticated user could read any workspace by
     * setting one header.
     */
    #[Test]
    public function a_user_cannot_resolve_a_tenant_they_do_not_belong_to(): void
    {
        $theirs = Tenant::factory()->create();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->withHeader('X-Tenant', $theirs->slug)
            ->getJson('/api/tenant')
            ->assertNotFound();
    }

    #[Test]
    public function unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/tenant')->assertUnauthorized();
        $this->getJson('/api/admin/metrics')->assertUnauthorized();
    }

    #[Test]
    public function the_platform_console_is_closed_to_merchants(): void
    {
        $merchant = User::factory()->create(['is_platform_admin' => false]);
        $staff = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($merchant)->getJson('/api/admin/metrics')->assertForbidden();
        $this->actingAs($staff)->getJson('/api/admin/metrics')->assertOk();
    }

    /** Every cross-tenant read by staff has to leave a trace. */
    #[Test]
    public function platform_reads_are_audited(): void
    {
        $staff = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($staff)->getJson('/api/admin/tenants')->assertOk();

        // Read in platform scope — no tenant bound — which is what the policy
        // returns platform entries for. A tenant-scoped read cannot see them,
        // which is the next test.
        $this->forgetTenant();

        $recorded = DB::table('audit_logs')
            ->where('user_id', $staff->id)
            ->where('action', 'platform.tenants.listed')
            ->whereNull('tenant_id')
            ->count();

        $this->assertSame(1, $recorded, 'A cross-tenant read by staff left no audit trail.');
    }

    /**
     * Platform audit entries record staff reaching across tenants. A tenant
     * being able to read them back would leak the existence and activity of
     * other tenants, so the policy permits writing a null-tenant row and
     * still refuses to return one.
     */
    #[Test]
    public function tenants_cannot_read_platform_audit_entries(): void
    {
        $staff = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($staff)->getJson('/api/admin/tenants')->assertOk();

        $this->app['auth']->forgetGuards();

        $tenant = Tenant::factory()->create();
        $this->actingAsTenant($tenant);

        $visible = DB::table('audit_logs')->whereNull('tenant_id')->count();

        $this->assertSame(0, $visible, 'A tenant could read platform-scope audit entries.');
    }
}
