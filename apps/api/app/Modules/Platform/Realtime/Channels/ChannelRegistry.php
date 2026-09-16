<?php

declare(strict_types=1);

namespace App\Modules\Platform\Realtime\Channels;

use App\Models\User;
use App\Modules\Platform\Identity\Models\Tenant;

/**
 * The single place channel names are constructed.
 *
 * Names are built here rather than interpolated at each call site because a
 * typo in a channel name is not a visible bug — the subscriber simply never
 * receives anything, or worse, listens on a name another tenant also derives.
 */
final class ChannelRegistry
{
    public static function tenant(Tenant|int $tenant): string
    {
        return 'tenant.'.($tenant instanceof Tenant ? $tenant->getKey() : $tenant);
    }

    public static function tenantPresence(Tenant|int $tenant): string
    {
        return 'presence-tenant.'.($tenant instanceof Tenant ? $tenant->getKey() : $tenant);
    }

    public static function user(User|int $user): string
    {
        return 'user.'.($user instanceof User ? $user->getKey() : $user);
    }

    public static function platform(): string
    {
        return 'platform';
    }

    /*
     | Route-definition patterns. Separate methods rather than passing a
     | placeholder string through the builders above, so the builders keep
     | their real types and a channel definition still cannot drift from the
     | name the publisher uses.
     */

    public static function tenantPattern(): string
    {
        return 'tenant.{tenantId}';
    }

    public static function tenantPresencePattern(): string
    {
        return 'presence-tenant.{tenantId}';
    }

    public static function userPattern(): string
    {
        return 'user.{userId}';
    }
}
