<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Platform\Billing\Models\Plan;
use App\Modules\Platform\Billing\Models\PlanFeature;
use App\Modules\Platform\Identity\Models\Tenant;
use App\Shared\Contracts\Entitlements;
use App\Shared\Events\QuotaThresholdReached;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EntitlementsTest extends TestCase
{
    private Tenant $tenant;

    private Entitlements $entitlements;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::factory()->create();

        PlanFeature::create(['plan_id' => $plan->id, 'feature_key' => 'orders', 'limit_value' => 10, 'overage_behavior' => 'block']);
        PlanFeature::create(['plan_id' => $plan->id, 'feature_key' => 'emails', 'limit_value' => 5, 'overage_behavior' => 'allow_and_bill']);
        PlanFeature::create(['plan_id' => $plan->id, 'feature_key' => 'products', 'limit_value' => null, 'overage_behavior' => 'block']);
        PlanFeature::create(['plan_id' => $plan->id, 'feature_key' => 'multi_branch', 'limit_value' => 0, 'overage_behavior' => 'block']);

        $this->tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        $this->actingAsTenant($this->tenant);

        $this->entitlements = app(Entitlements::class);
        $this->entitlements->flush($this->tenant);
    }

    #[Test]
    public function it_gates_features_by_plan(): void
    {
        $this->assertTrue($this->entitlements->can($this->tenant, 'orders'));
        $this->assertTrue($this->entitlements->can($this->tenant, 'products'));

        // A limit of 0 is an explicit denial, not an unset value.
        $this->assertFalse($this->entitlements->can($this->tenant, 'multi_branch'));

        // A feature the plan never mentions is denied, not implicitly granted.
        $this->assertFalse($this->entitlements->can($this->tenant, 'nonexistent_feature'));
    }

    #[Test]
    public function it_allows_consumption_within_the_limit(): void
    {
        $result = $this->entitlements->consume($this->tenant, 'orders', 3);

        $this->assertTrue($result->allowed);
        $this->assertFalse($result->overLimit);
        $this->assertSame(3, $result->used);
        $this->assertSame(7, $result->remaining());
    }

    #[Test]
    public function it_blocks_consumption_past_the_limit_when_the_plan_says_block(): void
    {
        $this->entitlements->consume($this->tenant, 'orders', 10);

        $result = $this->entitlements->consume($this->tenant, 'orders', 1);

        $this->assertTrue($result->blocked());
        $this->assertTrue($result->overLimit);

        // A refused action must not inflate the counter that refused it.
        $this->assertSame(10, $this->entitlements->used($this->tenant, 'orders'));
    }

    #[Test]
    public function it_permits_overage_when_the_plan_bills_for_it(): void
    {
        $result = $this->entitlements->consume($this->tenant, 'emails', 8);

        // Over the limit AND allowed — the two are deliberately separate so
        // the caller can proceed while still knowing to bill for it.
        $this->assertTrue($result->allowed);
        $this->assertTrue($result->overLimit);
        $this->assertSame('allow_and_bill', $result->overageBehavior);
    }

    #[Test]
    public function unlimited_features_never_block_and_report_no_remaining(): void
    {
        $result = $this->entitlements->consume($this->tenant, 'products', 100_000);

        $this->assertTrue($result->allowed);
        $this->assertNull($result->remaining());
        $this->assertNull($this->entitlements->remaining($this->tenant, 'products'));
    }

    #[Test]
    public function an_unmetered_metric_is_refused_rather_than_treated_as_unlimited(): void
    {
        $result = $this->entitlements->consume($this->tenant, 'typo_in_metric_key', 1);

        $this->assertTrue($result->blocked());
    }

    #[Test]
    public function it_announces_each_threshold_once(): void
    {
        Event::fake([QuotaThresholdReached::class]);

        $this->entitlements->consume($this->tenant, 'orders', 8);  // 80%
        $this->entitlements->consume($this->tenant, 'orders', 1);  // 90%, no new threshold
        $this->entitlements->consume($this->tenant, 'orders', 1);  // 100%

        Event::assertDispatchedTimes(QuotaThresholdReached::class, 2);

        Event::assertDispatched(
            QuotaThresholdReached::class,
            fn (QuotaThresholdReached $e): bool => $e->threshold === 80 && $e->metric === 'orders'
        );
        Event::assertDispatched(
            QuotaThresholdReached::class,
            fn (QuotaThresholdReached $e): bool => $e->threshold === 100
        );
    }

    #[Test]
    public function usage_is_scoped_per_tenant(): void
    {
        $this->entitlements->consume($this->tenant, 'orders', 5);

        $other = Tenant::factory()->create(['plan_id' => $this->tenant->plan_id]);
        $this->actingAsTenant($other);
        $this->entitlements->flush($other);

        $this->assertSame(0, $this->entitlements->used($other, 'orders'));
    }
}
