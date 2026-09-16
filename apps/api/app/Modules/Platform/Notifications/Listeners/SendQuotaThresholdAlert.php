<?php

declare(strict_types=1);

namespace App\Modules\Platform\Notifications\Listeners;

use App\Models\User;
use App\Modules\Platform\Identity\Models\Tenant;
use App\Modules\Platform\Notifications\Notifications\QuotaThresholdNotification;
use App\Modules\Platform\Realtime\Events\QuotaAlertBroadcast;
use App\Shared\Events\QuotaThresholdReached;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantDatabaseSession;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Turns a quota threshold into something the merchant actually sees.
 *
 * Queued: the event fires inside whatever request just consumed the quota,
 * and that request should not wait on mail, WhatsApp and a socket push.
 */
final class SendQuotaThresholdAlert implements ShouldQueue
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantDatabaseSession $session,
    ) {}

    public function handle(QuotaThresholdReached $event): void
    {
        $tenant = $event->tenant;

        // Re-established explicitly. A worker handles many tenants, and the
        // notification write below is tenant-scoped.
        $this->context->runAs($tenant, function () use ($event, $tenant): void {
            // runBound, not bind/clear: this listener may run inline inside a
            // request that already has a tenant bound.
            $this->session->runBound($tenant->getKey(), function () use ($event, $tenant): void {
                $recipients = User::query()
                    ->whereHas('tenants', fn ($query) => $query
                        ->whereKey($tenant->getKey())
                        // Only people who can act on it. Telling a member they
                        // are near a limit they cannot raise is noise.
                        ->whereIn('tenant_user.role', ['owner', 'admin'])
                        ->whereNotNull('tenant_user.joined_at'))
                    ->get();

                if ($recipients->isNotEmpty()) {
                    Notification::send($recipients, new QuotaThresholdNotification(
                        $tenant,
                        $event->metric,
                        $event->threshold,
                        $event->used,
                        $event->limit,
                    ));
                }

                $this->broadcast($event, $tenant);
            });
        });
    }

    /**
     * Team-wide live alert, so anyone with the dashboard open sees it —
     * including someone who is not a notification recipient.
     *
     * Failure here is logged and swallowed on purpose. The socket is the fast
     * path, never the only one: the in-app notification is already written,
     * and the dashboard refetches it over HTTP. Letting a websocket server
     * that is down turn into a failed upload would make the platform less
     * reliable than having no realtime at all.
     */
    private function broadcast(QuotaThresholdReached $event, Tenant $tenant): void
    {
        try {
            QuotaAlertBroadcast::dispatch(
                $tenant->getKey(),
                $event->metric,
                $event->threshold,
                $event->used,
                $event->limit,
            );
        } catch (Throwable $e) {
            Log::warning('Quota alert broadcast failed; the in-app notification still stands.', [
                'tenant_id' => $tenant->getKey(),
                'metric' => $event->metric,
                'threshold' => $event->threshold,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
