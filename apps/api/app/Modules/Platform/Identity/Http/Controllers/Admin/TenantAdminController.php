<?php

declare(strict_types=1);

namespace App\Modules\Platform\Identity\Http\Controllers\Admin;

use App\Modules\Platform\Audit\Services\AuditRecorder;
use App\Modules\Platform\Identity\Models\Tenant;
use App\Shared\Enums\TenantStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Super-admin tenant management.
 *
 * Runs in platform scope, which is the one legitimate way around tenant
 * isolation — so every read is audited. Queries here use the owner connection
 * because RLS policies deliberately have no "see everything" branch: adding
 * one would mean a bug in scope handling could expose the same rows to a
 * merchant request.
 */
final class TenantAdminController
{
    public function index(Request $request, AuditRecorder $audit): JsonResponse
    {
        $audit->recordPlatformAccess('tenants.listed', ['query' => $request->query()]);

        $tenants = Tenant::query()
            ->when($request->string('search')->toString(), fn ($q, string $search) => $q->where(
                fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('slug', 'ilike', "%{$search}%")
            ))
            ->when($request->string('status')->toString(), fn ($q, string $status) => $q->where('status', $status))
            ->when($request->string('product')->toString(), fn ($q, string $product) => $q->where('product', $product))
            ->withCount('users')
            ->latest('id')
            ->paginate(25);

        return response()->json($tenants);
    }

    public function show(Tenant $tenant, AuditRecorder $audit): JsonResponse
    {
        $audit->recordPlatformAccess('tenant.viewed', ['tenant_id' => $tenant->getKey()]);

        $owner = config('kavo.tenancy.owner_connection');

        return response()->json([
            'tenant' => $tenant->load('users'),
            'counts' => [
                'domains' => DB::connection($owner)->table('domains')->where('tenant_id', $tenant->getKey())->count(),
                'invoices' => DB::connection($owner)->table('invoices')->where('tenant_id', $tenant->getKey())->count(),
                'media' => DB::connection($owner)->table('media')->where('tenant_id', $tenant->getKey())->count(),
            ],
            'usage' => DB::connection($owner)->table('usage_counters')
                ->where('tenant_id', $tenant->getKey())
                ->where('period_start', now()->startOfMonth()->toDateString())
                ->get(['metric_key', 'value']),
        ]);
    }

    public function updateStatus(Request $request, Tenant $tenant, AuditRecorder $audit): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:pending,active,suspended,cancelled'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $before = $tenant->status->value;
        $tenant->update(['status' => TenantStatus::from($validated['status'])]);

        $audit->recordPlatformAccess('tenant.status_changed', [
            'tenant_id' => $tenant->getKey(),
            'from' => $before,
            'to' => $validated['status'],
            'reason' => $validated['reason'] ?? null,
        ]);

        return response()->json(['tenant' => $tenant->fresh()]);
    }
}
