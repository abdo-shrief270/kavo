<?php

declare(strict_types=1);

namespace App\Modules\Platform\Identity\Services;

use App\Modules\Platform\Identity\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * The single pre-tenant read path.
 *
 * Resolution has a bootstrapping problem: the tenant must be known before the
 * RLS GUC can be set, but the lookup itself reads the database. Rather than
 * weaken the policies to permit unscoped reads, the two tables involved in
 * resolution — `tenants` and `domains` — carry no policies, and this class is
 * the only place they are read without a tenant in context.
 *
 * Keeping that in one audited class is the point: every other read in the
 * application goes through the global scope and RLS.
 */
final class TenantLocator
{
    public function byHostname(string $hostname): ?Tenant
    {
        $root = (string) config('kavo.root_domain');

        if ($tenant = $this->byCustomDomain($hostname)) {
            return $tenant;
        }

        return $this->bySubdomain($hostname, $root);
    }

    public function bySlug(string $slug): ?Tenant
    {
        return Tenant::query()->where('slug', $slug)->first();
    }

    private function byCustomDomain(string $hostname): ?Tenant
    {
        $tenantId = DB::table('domains')
            ->where('hostname', $hostname)
            ->where('status', 'active')
            ->value('tenant_id');

        if ($tenantId === null) {
            return null;
        }

        return Tenant::query()->whereKey($tenantId)->first();
    }

    private function bySubdomain(string $hostname, string $root): ?Tenant
    {
        if ($hostname === $root || ! str_ends_with($hostname, '.'.$root)) {
            return null;
        }

        $slug = substr($hostname, 0, -(strlen($root) + 1));

        // Reserved subdomains are platform surfaces, never tenants.
        if (in_array($slug, ['www', 'api', 'admin', 'app', 'ws', 'stg'], true)) {
            return null;
        }

        return $this->bySlug($slug);
    }

    /**
     * Used by the Caddy on-demand TLS ask endpoint: Caddy will only request a
     * certificate for a hostname this returns true for.
     */
    public function hostnameIsIssuable(string $hostname): bool
    {
        return DB::table('domains')
            ->where('hostname', $hostname)
            ->whereIn('status', ['verifying', 'active'])
            ->exists();
    }
}
