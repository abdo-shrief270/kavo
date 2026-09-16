<?php

declare(strict_types=1);

namespace App\Shared\Events;

use App\Modules\Platform\Billing\Models\PaymentIntent;
use App\Shared\Enums\PaymentStatus;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A payment reached a terminal state.
 *
 * The seam the Commerce module will consume in Phase 1: an order commits
 * inventory on Succeeded and releases its reservation on Expired or Failed.
 * Platform modules never reach into a vertical, so this is how that
 * conversation happens.
 */
final class PaymentSettled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly PaymentIntent $intent,
        public readonly PaymentStatus $from,
        public readonly PaymentStatus $to,
    ) {}

    public function succeeded(): bool
    {
        return $this->to === PaymentStatus::Succeeded;
    }

    /** The reservation should be released and the stock returned. */
    public function releasesReservation(): bool
    {
        return in_array($this->to, [PaymentStatus::Expired, PaymentStatus::Failed], true);
    }
}
