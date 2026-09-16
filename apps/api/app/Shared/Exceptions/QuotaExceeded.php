<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use App\Modules\Platform\Entitlements\Results\ConsumeResult;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Renders as 402 Payment Required rather than 403.
 *
 * The distinction is deliberate and the frontends act on it: 403 means "you
 * may not do this", 402 means "you may, on a larger plan" — which is an
 * upgrade prompt, not an error.
 */
final class QuotaExceeded extends RuntimeException
{
    public function __construct(public readonly ConsumeResult $result)
    {
        parent::__construct(sprintf(
            'Quota exceeded for %s (%d of %s used).',
            $result->metric,
            $result->used,
            $result->limit === null ? 'unlimited' : (string) $result->limit,
        ));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => "You have reached your {$this->result->metric} limit for this period.",
            'quota' => [
                'metric' => $this->result->metric,
                'used' => $this->result->used,
                'limit' => $this->result->limit,
                'remaining' => $this->result->remaining(),
                'overage_behavior' => $this->result->overageBehavior,
            ],
        ], 402);
    }
}
