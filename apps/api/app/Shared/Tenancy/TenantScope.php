<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query on a tenant-scoped model to the resolved tenant.
 *
 * This is the first of two layers. Postgres row-level security is the second,
 * and it is the one that still holds when this scope is bypassed — by a raw
 * query, a missing trait, or an explicit withoutGlobalScopes() call.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->inPlatformScope()) {
            return;
        }

        $builder->where(
            $model->qualifyColumn('tenant_id'),
            $context->getOrFail('querying '.$model::class)->getKey()
        );
    }
}
