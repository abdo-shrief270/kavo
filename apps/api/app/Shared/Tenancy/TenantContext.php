<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use App\Modules\Platform\Identity\Models\Tenant;
use App\Shared\Exceptions\TenantContextMissing;

/**
 * Holds the tenant for the current request or job.
 *
 * Bound as `scoped`, never `singleton` and never static: under Octane the
 * container persists between requests, so a singleton here would carry one
 * tenant's identity into the next tenant's request.
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    /** Set while running deliberately across tenants (platform admin, rollups). */
    private bool $platformScope = false;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->platformScope = false;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function getOrFail(string $context = 'resolving the tenant'): Tenant
    {
        return $this->tenant ?? throw TenantContextMissing::make($context);
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    /**
     * Enter platform scope, where the tenant global scope does not apply.
     *
     * Only the super-admin surface and scheduled rollups use this, and every
     * entry is audited by the caller.
     */
    public function enterPlatformScope(): void
    {
        $this->tenant = null;
        $this->platformScope = true;
    }

    public function inPlatformScope(): bool
    {
        return $this->platformScope;
    }

    /**
     * Run a callback as a given tenant, restoring the previous state after.
     * Used by queue jobs, which must re-establish context explicitly rather
     * than inherit whatever the worker last handled.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAs(Tenant $tenant, callable $callback): mixed
    {
        $previousTenant = $this->tenant;
        $previousScope = $this->platformScope;

        $this->set($tenant);

        try {
            return $callback();
        } finally {
            $this->tenant = $previousTenant;
            $this->platformScope = $previousScope;
        }
    }

    /**
     * Clear all tenant state. Called on request termination under Octane and
     * after every queued job.
     */
    public function forget(): void
    {
        $this->tenant = null;
        $this->platformScope = false;
    }
}
