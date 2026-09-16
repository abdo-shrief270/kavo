<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Platform\Identity\Models\Tenant;
use App\Modules\Platform\Realtime\Channels\ChannelRegistry;
use Illuminate\Support\Facades\Broadcast;

/**
 * Channel authorisation.
 *
 * An unverified callback here is a cross-tenant data leak: returning true
 * unconditionally would let any authenticated user subscribe to any tenant's
 * channel and receive its events in real time. Every callback below checks
 * membership.
 */
Broadcast::channel(ChannelRegistry::tenantPattern(), function (User $user, string $tenantId): bool {
    $tenant = Tenant::query()->find($tenantId);

    return $tenant !== null && $user->belongsToTenant($tenant);
});

Broadcast::channel(ChannelRegistry::userPattern(), function (User $user, string $userId): bool {
    return (int) $user->getKey() === (int) $userId;
});

/** Presence channel for team collaboration inside one tenant. */
Broadcast::channel(ChannelRegistry::tenantPresencePattern(), function (User $user, string $tenantId): ?array {
    $tenant = Tenant::query()->find($tenantId);

    if ($tenant === null || ! $user->belongsToTenant($tenant)) {
        return null;
    }

    return ['id' => $user->getKey(), 'name' => $user->name];
});

/** Platform staff only — never scoped to a tenant. */
Broadcast::channel(ChannelRegistry::platform(), function (User $user): bool {
    return (bool) $user->is_platform_admin;
});
