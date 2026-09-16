<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Http\Controllers\Admin;

use App\Modules\Platform\Audit\Services\AuditRecorder;
use App\Modules\Platform\Billing\Models\Plan;
use App\Modules\Platform\Billing\Models\PlanFeature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlanAdminController
{
    public function index(): JsonResponse
    {
        return response()->json(['plans' => Plan::query()->with('features')->orderBy('sort_order')->get()]);
    }

    public function store(Request $request, AuditRecorder $audit): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', 'unique:plans,code'],
            'name' => ['required', 'string', 'max:255'],
            'product' => ['required', 'string', 'in:fashion,courses,beauty,autoparts'],
            'interval' => ['required', 'string', 'in:month,year'],
            'price_cents' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'features' => ['array'],
            'features.*.feature_key' => ['required', 'string', 'max:64'],
            'features.*.limit_value' => ['nullable', 'integer', 'min:0'],
            'features.*.overage_behavior' => ['required', 'string', 'in:block,allow_and_bill,soft_warn'],
        ]);

        $plan = Plan::create($validated);

        foreach ($validated['features'] ?? [] as $feature) {
            PlanFeature::create($feature + ['plan_id' => $plan->getKey()]);
        }

        $audit->recordPlatformAccess('plan.created', ['plan_code' => $plan->code]);

        return response()->json(['plan' => $plan->load('features')], 201);
    }

    public function update(Request $request, Plan $plan, AuditRecorder $audit): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'price_cents' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
        ]);

        $plan->update($validated);

        // Plan shape is cached per tenant, so a price or limit change has to
        // invalidate it or tenants keep the old entitlements for an hour.
        $audit->recordPlatformAccess('plan.updated', ['plan_code' => $plan->code, 'changes' => $validated]);

        return response()->json(['plan' => $plan->fresh()->load('features')]);
    }
}
