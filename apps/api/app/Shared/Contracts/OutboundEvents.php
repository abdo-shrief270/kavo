<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Modules\Platform\Identity\Models\Tenant;

/**
 * Tells a tenant's own systems that something happened in theirs.
 *
 * A vertical raising `order.paid` should not know that the platform delivers
 * it over HTTP with HMAC signatures and exponential backoff, any more than it
 * knows how storage is metered. It knows there is somewhere to say it.
 *
 * This exists as a contract rather than a direct call for the reason the whole
 * module layout exists: Commerce may use the platform, the platform may never
 * use Commerce, and the narrower the surface Commerce depends on, the less of
 * the platform has to stay still.
 */
interface OutboundEvents
{
    /**
     * Fan an event out to every subscription that asked for it.
     *
     * @param  array<string, mixed>  $payload
     * @return int how many subscriptions it went to
     */
    public function publish(Tenant $tenant, string $eventType, array $payload): int;
}
