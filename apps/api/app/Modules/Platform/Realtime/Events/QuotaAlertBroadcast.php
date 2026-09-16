<?php

declare(strict_types=1);

namespace App\Modules\Platform\Realtime\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Live quota alert for the merchant dashboard.
 *
 * Broadcast is the fast path, never the only one: the same alert is also
 * written to the notifications table, so a dropped socket costs immediacy
 * rather than the message itself.
 */
final class QuotaAlertBroadcast extends BroadcastsToTenant implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        int $tenantId,
        public readonly string $metric,
        public readonly int $threshold,
        public readonly int $used,
        public readonly ?int $limit,
    ) {
        parent::__construct($tenantId);
    }

    public function broadcastAs(): string
    {
        return 'quota.threshold';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'metric' => $this->metric,
            'threshold' => $this->threshold,
            'used' => $this->used,
            'limit' => $this->limit,
            'at_limit' => $this->threshold >= 100,
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
