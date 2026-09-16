<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Notifications\Models\NotificationPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotificationPreference> */
final class NotificationPreferenceFactory extends Factory
{
    protected $model = NotificationPreference::class;

    public function definition(): array
    {
        return [
            'channel' => 'mail',
            'event_type' => 'quota.threshold',
            'enabled' => true,
        ];
    }
}
