<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Identity\Models\Tenant;
use App\Shared\Enums\Product;
use App\Shared\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Tenant> */
final class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => TenantStatus::Active,
            'product' => Product::Fashion,
            'settings' => [],
        ];
    }
}
