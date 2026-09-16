<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use Illuminate\Http\Request;

/**
 * Providers cannot log in, so inbound callbacks are authenticated by
 * signature rather than by session. One verifier per provider.
 */
interface WebhookVerifier
{
    /** Verified against the RAW body — never a re-encoded payload. */
    public function verify(Request $request): bool;

    /**
     * The provider's own event id, used as the idempotency key. Returning
     * null means the event cannot be de-duplicated and must be rejected.
     */
    public function externalEventId(Request $request): ?string;

    public function eventType(Request $request): ?string;
}
