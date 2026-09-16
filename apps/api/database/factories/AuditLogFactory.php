<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Audit\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuditLog> */
final class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    public function definition(): array
    {
        return [
            'action' => 'tenant.updated',
            'old_values' => [],
            'new_values' => [],
            'ip' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ];
    }
}
