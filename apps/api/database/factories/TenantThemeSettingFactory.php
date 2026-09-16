<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Themes\Models\TenantThemeSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TenantThemeSetting> */
final class TenantThemeSettingFactory extends Factory
{
    protected $model = TenantThemeSetting::class;

    public function definition(): array
    {
        return [
            'theme_code' => 'default',
            'settings' => ['hero' => ['headline' => fake()->catchPhrase()]],
            'design_tokens' => ['color' => ['primary' => '#111827']],
            'is_published' => true,
        ];
    }
}
