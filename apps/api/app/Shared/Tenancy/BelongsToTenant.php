<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use App\Modules\Platform\Identity\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as tenant-scoped.
 *
 * Applying this trait does three things: filters reads to the current tenant,
 * stamps tenant_id on create, and enrols the model in the generated isolation
 * test suite. CI fails if a model using this trait has no isolation coverage,
 * so the trait is the single place tenancy is declared.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (self $model): void {
            if ($model->getAttribute('tenant_id') !== null) {
                return;
            }

            $context = app(TenantContext::class);

            // In platform scope the caller is responsible for supplying
            // tenant_id explicitly; guessing one here would be a data bug.
            if ($context->inPlatformScope()) {
                return;
            }

            $model->setAttribute(
                'tenant_id',
                $context->getOrFail('creating '.static::class)->getKey()
            );
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
