<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Media\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Media> */
final class MediaFactory extends Factory
{
    protected $model = Media::class;

    public function definition(): array
    {
        $name = Str::lower(Str::random(10)).'.jpg';

        return [
            'disk' => 'public',
            'path' => 'media/'.$name,
            'filename' => $name,
            'mime' => 'image/jpeg',
            'size_bytes' => fake()->numberBetween(1000, 5_000_000),
            'checksum' => hash('sha256', $name),
            'meta' => [],
        ];
    }
}
