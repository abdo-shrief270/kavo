<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Platform\Identity\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An unverified channel callback is a cross-tenant data leak: it would let
 * any authenticated user subscribe to any tenant's channel and receive that
 * tenant's events live. These tests assert the rejections, not just the
 * approvals — the approval passing proves nothing on its own.
 */
final class ChannelAuthorizationTest extends TestCase
{
    private function authorize(User $user, string $channel): int
    {
        return $this->actingAs($user)
            ->postJson('/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => '1234.5678'])
            ->getStatusCode();
    }

    #[Test]
    public function a_member_may_join_their_own_tenant_channel(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $tenant->users()->attach($user, ['role' => 'owner', 'joined_at' => now()]);

        $this->assertSame(200, $this->authorize($user, 'private-tenant.'.$tenant->id));
    }

    #[Test]
    public function a_non_member_is_rejected_from_a_tenant_channel(): void
    {
        $tenant = Tenant::factory()->create();
        $outsider = User::factory()->create();

        $this->assertSame(403, $this->authorize($outsider, 'private-tenant.'.$tenant->id));
    }

    #[Test]
    public function a_member_of_one_tenant_cannot_join_another_tenants_channel(): void
    {
        $mine = Tenant::factory()->create();
        $theirs = Tenant::factory()->create();

        $user = User::factory()->create();
        $mine->users()->attach($user, ['role' => 'owner', 'joined_at' => now()]);

        $this->assertSame(403, $this->authorize($user, 'private-tenant.'.$theirs->id));
    }

    #[Test]
    public function a_user_cannot_join_another_users_personal_channel(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->assertSame(200, $this->authorize($user, 'private-user.'.$user->id));
        $this->assertSame(403, $this->authorize($user, 'private-user.'.$other->id));
    }

    #[Test]
    public function only_platform_staff_may_join_the_platform_channel(): void
    {
        $merchant = User::factory()->create(['is_platform_admin' => false]);
        $staff = User::factory()->create(['is_platform_admin' => true]);

        $this->assertSame(403, $this->authorize($merchant, 'private-platform'));
        $this->assertSame(200, $this->authorize($staff, 'private-platform'));
    }

    #[Test]
    public function presence_channels_are_membership_checked_too(): void
    {
        $tenant = Tenant::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $tenant->users()->attach($member, ['role' => 'member', 'joined_at' => now()]);

        $this->assertSame(200, $this->authorize($member, 'presence-tenant.'.$tenant->id));
        $this->assertSame(403, $this->authorize($outsider, 'presence-tenant.'.$tenant->id));
    }
}
