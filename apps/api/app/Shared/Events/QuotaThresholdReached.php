<?php

declare(strict_types=1);

namespace App\Shared\Events;

use App\Modules\Platform\Identity\Models\Tenant;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emitted once per threshold per period. The notification module turns it
 * into in-app, email and WhatsApp alerts — which is also the natural upsell
 * trigger, so it carries enough context to render one.
 */
final class QuotaThresholdReached
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $metric,
        public readonly int $threshold,
        public readonly int $used,
        public readonly ?int $limit,
    ) {}
}
