<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum Product: string
{
    case Fashion = 'fashion';
    case Courses = 'courses';
    case Beauty = 'beauty';
    case AutoParts = 'autoparts';

    public function label(): string
    {
        return match ($this) {
            self::Fashion => 'Fashion',
            self::Courses => 'Courses',
            self::Beauty => 'Beauty',
            self::AutoParts => 'Auto Parts',
        };
    }
}
