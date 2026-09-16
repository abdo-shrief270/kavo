<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Billing\Models\IdempotencyKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<IdempotencyKey> */
final class IdempotencyKeyFactory extends Factory
{
    protected $model = IdempotencyKey::class;

    public function definition(): array
    {
        return [
            'key' => (string) Str::uuid(),
            'method' => 'POST',
            'path' => 'api/payments',
            'request_hash' => hash('sha256', Str::random(32)),
            'locked_at' => now(),
            'expires_at' => now()->addDay(),
        ];
    }

    /** A key that already carries the response it will replay. */
    public function completed(): self
    {
        return $this->state(fn (): array => [
            'response_status' => 201,
            'response_body' => ['payment' => ['status' => 'succeeded']],
        ]);
    }
}
