<?php

declare(strict_types=1);

namespace App\Modules\Platform\Identity\Http\Controllers;

use App\Modules\Platform\Audit\Services\AuditRecorder;
use App\Shared\Contracts\Entitlements;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TenantController
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(Entitlements $entitlements): JsonResponse
    {
        $tenant = $this->context->getOrFail('reading the current workspace');

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status->value,
                'product' => $tenant->product->value,
                'trial_ends_at' => $tenant->trial_ends_at,
                'on_trial' => $tenant->isOnTrial(),
                'settings' => $tenant->settings,
            ],
            'usage' => $this->usageSummary($entitlements),
        ]);
    }

    public function update(Request $request, AuditRecorder $audit): JsonResponse
    {
        $tenant = $this->context->getOrFail('updating the current workspace');

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'settings' => ['sometimes', 'array'],
        ]);

        $before = $tenant->only(['name', 'settings']);
        $tenant->fill($validated)->save();

        $audit->record('tenant.updated', $tenant, $before, $tenant->only(['name', 'settings']));

        return response()->json(['tenant' => $tenant->fresh()]);
    }

    /** @return array<int, array<string, mixed>> */
    private function usageSummary(Entitlements $entitlements): array
    {
        $tenant = $this->context->getOrFail('summarising usage');
        $metrics = ['orders', 'products', 'storage_mb', 'whatsapp_messages'];

        return array_map(fn (string $metric): array => [
            'metric' => $metric,
            'used' => $entitlements->used($tenant, $metric),
            'remaining' => $entitlements->remaining($tenant, $metric),
        ], $metrics);
    }
}
