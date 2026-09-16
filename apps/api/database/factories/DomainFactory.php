<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Domains\Models\Domain;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Domain> */
final class DomainFactory extends Factory
{
    protected $model = Domain::class;

    public function definition(): array
    {
        return [
            'hostname' => Str::lower(Str::random(8)).'.example.com',
            'status' => 'pending',
            'verification_token' => Str::random(32),
        ];
    }

    public function active(): self
    {
        return $this->state(fn (): array => ['status' => 'active', 'verified_at' => now()]);
    }
}
