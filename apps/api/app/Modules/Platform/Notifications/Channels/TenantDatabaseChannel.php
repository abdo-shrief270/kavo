<?php

declare(strict_types=1);

namespace App\Modules\Platform\Notifications\Channels;

use App\Shared\Tenancy\TenantContext;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * Laravel's database channel, taught about tenancy.
 *
 * The `notifications` table is tenant-scoped, so a row written without a
 * tenant_id is rejected outright by its row-level security policy. The stock
 * channel has no way to know that, so it is extended here rather than the
 * table being exempted — an in-app notification is tenant data like any
 * other, and one tenant must not read another's.
 *
 * The tenant is taken from the notification itself when it carries one, so a
 * queued send resolves the right workspace instead of whatever the worker
 * last handled.
 */
final class TenantDatabaseChannel extends DatabaseChannel
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return array<string, mixed> */
    protected function buildPayload($notifiable, Notification $notification): array
    {
        return array_merge(parent::buildPayload($notifiable, $notification), [
            'tenant_id' => $this->tenantIdFor($notification),
        ]);
    }

    private function tenantIdFor(Notification $notification): ?int
    {
        if (property_exists($notification, 'tenant') && $notification->tenant !== null) {
            return $notification->tenant->getKey();
        }

        return $this->context->id();
    }
}
