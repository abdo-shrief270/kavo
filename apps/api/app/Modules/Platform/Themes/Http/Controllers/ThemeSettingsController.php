<?php

declare(strict_types=1);

namespace App\Modules\Platform\Themes\Http\Controllers;

use App\Modules\Platform\Audit\Services\AuditRecorder;
use App\Modules\Platform\Themes\Models\TenantThemeSetting;
use App\Modules\Platform\Themes\Models\Theme;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ThemeSettingsController
{
    public function index(): JsonResponse
    {
        return response()->json([
            'available' => Theme::query()->where('is_active', true)->get(['code', 'name', 'product', 'version']),
            'configured' => TenantThemeSetting::query()->get(),
        ]);
    }

    public function update(Request $request, AuditRecorder $audit): JsonResponse
    {
        $validated = $request->validate([
            'theme_code' => ['required', 'string', 'exists:themes,code'],
            'settings' => ['array'],
            'design_tokens' => ['array'],
            'is_published' => ['boolean'],
        ]);

        $setting = TenantThemeSetting::updateOrCreate(
            ['theme_code' => $validated['theme_code']],
            [
                'settings' => $validated['settings'] ?? [],
                'design_tokens' => $validated['design_tokens'] ?? [],
                'is_published' => $validated['is_published'] ?? false,
            ]
        );

        $audit->record('theme.updated', $setting);

        return response()->json(['setting' => $setting]);
    }
}
