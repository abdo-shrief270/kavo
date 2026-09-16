<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Platform\Analytics\Models\AnalyticsEvent;
use App\Modules\Platform\Identity\Models\Tenant;
use App\Shared\Exceptions\TenantContextMissing;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TenantScopedModels;
use Tests\TestCase;
use Throwable;

/**
 * The cheapest bug prevention in the project.
 *
 * Single-database tenancy makes a missing scope catastrophic rather than
 * merely wrong, so every tenant-scoped model is tested — and the list is
 * discovered from the BelongsToTenant trait, not maintained by hand. A new
 * model is enrolled the moment it declares itself tenant-scoped.
 */
final class TenantIsolationTest extends TestCase
{
    /**
     * Models with no factory. Each needs a reason, so the list cannot quietly
     * grow into an escape hatch.
     *
     * @var array<int, class-string<Model>>
     */
    private const WITHOUT_FACTORIES = [
        // Written only through AnalyticsIngestor's batch insert; isolation is
        // covered directly against the partitioned table in
        // RowLevelSecurityTest.
        AnalyticsEvent::class,
    ];

    /** @return array<string, array{class-string<Model>}> */
    public static function tenantScopedModels(): array
    {
        $cases = [];

        foreach (TenantScopedModels::all() as $model) {
            if (in_array($model, self::WITHOUT_FACTORIES, true)) {
                continue;
            }

            $cases[class_basename($model)] = [$model];
        }

        return $cases;
    }

    #[Test]
    public function it_discovers_tenant_scoped_models(): void
    {
        $this->assertNotEmpty(
            TenantScopedModels::all(),
            'Discovery found no tenant-scoped models, which means this entire suite is silently vacuous.'
        );
    }

    /**
     * Failing the build is the point: a tenant-scoped model with no factory
     * cannot be proven isolated, and skipping it quietly is exactly how a
     * model ships with no isolation coverage.
     */
    #[Test]
    public function every_tenant_scoped_model_has_a_factory(): void
    {
        $uncovered = [];

        foreach (TenantScopedModels::all() as $model) {
            if (in_array($model, self::WITHOUT_FACTORIES, true)) {
                continue;
            }

            try {
                $model::factory();
            } catch (Throwable) {
                $uncovered[] = $model;
            }
        }

        $this->assertSame([], $uncovered, 'Tenant-scoped models without a factory: '.implode(', ', $uncovered));
    }

    #[Test]
    #[DataProvider('tenantScopedModels')]
    public function it_never_returns_another_tenants_rows(string $model): void
    {
        $alpha = Tenant::factory()->create();
        $beta = Tenant::factory()->create();

        $mine = $this->asTenant($alpha, fn (): Model => $model::factory()->create());
        $theirs = $this->asTenant($beta, fn (): Model => $model::factory()->create());

        $this->actingAsTenant($alpha);

        $visible = $model::query()->pluck('id')->all();

        $this->assertContains($mine->getKey(), $visible);
        $this->assertNotContains($theirs->getKey(), $visible);
        $this->assertNull($model::query()->find($theirs->getKey()));
    }

    #[Test]
    #[DataProvider('tenantScopedModels')]
    public function it_stamps_the_current_tenant_on_create(string $model): void
    {
        $tenant = Tenant::factory()->create();

        $record = $this->asTenant($tenant, fn (): Model => $model::factory()->create());

        $this->assertSame($tenant->getKey(), $record->tenant_id);
    }

    /**
     * Failing loudly beats defaulting to a tenant. A row written under the
     * wrong owner is unrecoverable; a thrown exception is a bug report.
     */
    #[Test]
    #[DataProvider('tenantScopedModels')]
    public function it_refuses_to_create_rows_with_no_tenant_resolved(string $model): void
    {
        $this->forgetTenant();

        $this->expectException(TenantContextMissing::class);

        $model::factory()->create();
    }

    #[Test]
    #[DataProvider('tenantScopedModels')]
    public function it_refuses_to_read_with_no_tenant_resolved(string $model): void
    {
        $this->forgetTenant();

        $this->expectException(TenantContextMissing::class);

        $model::query()->get();
    }
}
