<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum TenantStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    public function canServeRequests(): bool
    {
        return $this === self::Active;
    }
}
