<?php

declare(strict_types=1);

namespace App\Modules\Platform\Themes\Http\Controllers;

use App\Modules\Platform\Themes\Models\TenantThemeSetting;
use App\Modules\Platform\Themes\Models\Theme;
use App\Shared\Contracts\AnalyticsIngestor;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * Everything the Nuxt storefront needs to render a tenant's site, in one
 * request: identity, theme manifest, design tokens and published settings.
 *
 * One call rather than several because this is on the critical path of every
 * cold SSR render — each extra round trip is added latency on first paint.
 */
final class StorefrontConfigController
{
    public function __invoke(TenantContext $context, AnalyticsIngestor $analytics): JsonResponse
    {
        $tenant = $context->getOrFail('building storefront config');

        $settings = TenantThemeSetting::query()
            ->where('is_published', true)
            ->latest('updated_at')
            ->first();

        $theme = $settings === null
            ? null
            : Theme::query()->where('code', $settings->theme_code)->where('is_active', true)->latest('id')->first();

        $analytics->record('storefront.config_served', ['theme' => $settings?->theme_code]);

        return response()->json([
            'tenant' => [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'product' => $tenant->product->value,
            ],
            'theme' => $theme === null ? null : [
                'code' => $theme->code,
                'version' => $theme->version,
                'sections' => $theme->sections(),
            ],
            'design_tokens' => $settings->design_tokens ?? [],
            'settings' => $settings->settings ?? [],
        ])->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=300');
    }
}
