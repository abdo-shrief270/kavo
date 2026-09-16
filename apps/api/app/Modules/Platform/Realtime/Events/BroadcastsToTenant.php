<?php

declare(strict_types=1);

namespace App\Modules\Platform\Realtime\Events;

use App\Modules\Platform\Identity\Models\Tenant;
use App\Modules\Platform\Realtime\Channels\ChannelRegistry;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Base for anything broadcast to one tenant's whole team.
 *
 * Every event carries its tenant explicitly rather than reading ambient
 * context: these are usually dispatched from queued jobs, where the worker's
 * current tenant is whatever it last handled.
 */
abstract class BroadcastsToTenant
{
    public function __construct(public readonly int $tenantId) {}

    public static function for(Tenant $tenant, mixed ...$arguments): static
    {
        return new static($tenant->getKey(), ...$arguments);
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(ChannelRegistry::tenant($this->tenantId))];
    }
}
