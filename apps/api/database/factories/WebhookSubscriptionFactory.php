<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Webhooks\Models\WebhookSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<WebhookSubscription> */
final class WebhookSubscriptionFactory extends Factory
{
    protected $model = WebhookSubscription::class;

    public function definition(): array
    {
        return [
            'url' => 'https://'.Str::lower(Str::random(8)).'.example.com/hooks',
            'secret' => Str::random(48),
            'event_types' => ['order.placed'],
            'is_active' => true,
        ];
    }
}
