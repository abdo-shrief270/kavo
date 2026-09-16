<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Platform\Billing\Models\Plan;
use App\Modules\Platform\Billing\Models\PlanFeature;
use App\Modules\Platform\Identity\Models\Tenant;
use App\Modules\Platform\Media\Models\Media;
use App\Modules\Platform\Notifications\Notifications\QuotaThresholdNotification;
use App\Modules\Platform\Realtime\Events\QuotaAlertBroadcast;
use App\Shared\Contracts\Entitlements;
use App\Shared\Events\QuotaThresholdReached;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The whole quota loop, end to end:
 *
 *   metered write → threshold event → multi-channel notification
 *                                   → live broadcast to the tenant's team
 *                 → block at the limit
 *
 * Every link is tested because each one has a distinct failure mode that
 * looks like silence: a meter nobody calls, an event nobody listens for, a
 * notification rejected by its own RLS policy, or a broadcast on a channel
 * name nothing subscribes to.
 */
final class QuotaLoopTest extends TestCase
{
    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $plan = Plan::factory()->create();

        // 10 MB, blocking. Small enough that a handful of 1 MB uploads cross
        // both thresholds.
        PlanFeature::create([
            'plan_id' => $plan->id,
            'feature_key' => 'storage_mb',
            'limit_value' => 10,
            'overage_behavior' => 'block',
        ]);

        $this->tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        $this->owner = User::factory()->create(['phone' => '+201000000000']);
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);

        $this->actingAsTenant($this->tenant);
        app(Entitlements::class)->flush($this->tenant);
    }

    private function upload(int $megabytes = 1): TestResponse
    {
        return $this->actingAs($this->owner)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/media', [
                'file' => UploadedFile::fake()->create('photo.jpg', $megabytes * 1024, 'image/jpeg'),
            ]);
    }

    #[Test]
    public function an_upload_is_metered_against_storage(): void
    {
        $response = $this->upload(2);

        $response->assertStatus(201)
            ->assertJsonPath('quota.metric', 'storage_mb')
            ->assertJsonPath('quota.used', 2)
            ->assertJsonPath('quota.remaining', 8);

        $this->assertDatabaseCount('media', 1);
    }

    #[Test]
    public function crossing_eighty_percent_raises_the_threshold_event(): void
    {
        Event::fake([QuotaThresholdReached::class]);

        $this->upload(7)->assertStatus(201);
        Event::assertNotDispatched(QuotaThresholdReached::class);

        $this->upload(1)->assertStatus(201);

        Event::assertDispatched(
            QuotaThresholdReached::class,
            fn (QuotaThresholdReached $e): bool => $e->threshold === 80
                && $e->metric === 'storage_mb'
                && $e->tenant->is($this->tenant),
        );
    }

    #[Test]
    public function the_threshold_produces_an_in_app_notification_and_a_live_broadcast(): void
    {
        Notification::fake();
        Event::fake([QuotaAlertBroadcast::class]);

        $this->upload(8)->assertStatus(201);

        Notification::assertSentTo(
            $this->owner,
            QuotaThresholdNotification::class,
            fn (QuotaThresholdNotification $n): bool => $n->metric === 'storage_mb' && $n->threshold === 80,
        );

        // Broadcast to the whole team, so someone with the dashboard open
        // sees it even if they are not a notification recipient.
        Event::assertDispatched(
            QuotaAlertBroadcast::class,
            fn (QuotaAlertBroadcast $e): bool => $e->tenantId === $this->tenant->getKey()
                && $e->metric === 'storage_mb',
        );
    }

    #[Test]
    public function the_in_app_notification_is_persisted_and_tenant_scoped(): void
    {
        // Not faked: this asserts the notification survives its own RLS
        // policy, which the framework's database channel would fail.
        $this->upload(8)->assertStatus(201);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->owner->id,
            'tenant_id' => $this->tenant->getKey(),
            'type' => 'quota.threshold',
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/notifications');

        $response->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('notifications.0.data.metric', 'storage_mb');
    }

    #[Test]
    public function the_broadcast_targets_the_tenants_private_channel(): void
    {
        $event = new QuotaAlertBroadcast($this->tenant->getKey(), 'storage_mb', 80, 8, 10);

        $channels = array_map(fn ($channel): string => (string) $channel, $event->broadcastOn());

        // A wrong name here is silent: nothing errors, the merchant just
        // never hears anything.
        $this->assertSame(['private-tenant.'.$this->tenant->getKey()], $channels);
        $this->assertSame('quota.threshold', $event->broadcastAs());
        $this->assertSame(80, $event->broadcastWith()['threshold']);
        $this->assertFalse($event->broadcastWith()['at_limit']);
    }

    #[Test]
    public function exceeding_the_limit_is_blocked_with_an_upgrade_signal(): void
    {
        $this->upload(10)->assertStatus(201);

        $response = $this->upload(1);

        // 402, not 403: the tenant may do this, on a larger plan. The
        // frontends render an upgrade prompt rather than an error.
        $response->assertStatus(402)
            ->assertJsonPath('quota.metric', 'storage_mb')
            ->assertJsonPath('quota.remaining', 0);

        // The refused upload wrote nothing — one file on disk and one row,
        // both from the upload that was allowed. Charging before the write is
        // what keeps a blocked tenant from consuming disk they cannot pay for.
        $this->assertDatabaseCount('media', 1);
        $this->assertCount(
            1,
            Storage::disk('public')->files('tenants/'.$this->tenant->getKey().'/media'),
        );
    }

    #[Test]
    public function deleting_returns_the_allowance(): void
    {
        $created = $this->upload(4)->assertStatus(201)->json('media.id');

        $this->assertSame(4, app(Entitlements::class)->used($this->tenant, 'storage_mb'));

        $this->actingAs($this->owner)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->deleteJson("/api/media/{$created}")
            ->assertOk()
            ->assertJsonPath('quota.used', 0)
            ->assertJsonPath('quota.remaining', 10);

        // storage_mb is a stock metric, not a flow. Charging on upload and
        // never refunding would bill for bytes that no longer exist.
        $this->assertSame(0, app(Entitlements::class)->used($this->tenant, 'storage_mb'));
    }

    #[Test]
    public function usage_and_alerts_do_not_leak_between_tenants(): void
    {
        $this->upload(8)->assertStatus(201);

        $other = Tenant::factory()->create(['plan_id' => $this->tenant->plan_id]);
        $this->actingAsTenant($other);
        app(Entitlements::class)->flush($other);

        $this->assertSame(0, app(Entitlements::class)->used($other, 'storage_mb'));
        $this->assertSame(0, Media::query()->count());
    }
}
