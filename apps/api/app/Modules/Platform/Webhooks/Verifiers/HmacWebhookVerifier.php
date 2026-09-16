<?php

declare(strict_types=1);

namespace App\Modules\Platform\Webhooks\Verifiers;

use App\Shared\Contracts\WebhookVerifier;
use Illuminate\Http\Request;

/**
 * HMAC-SHA256 over the raw request body, with an optional timestamp header
 * for replay protection.
 */
class HmacWebhookVerifier implements WebhookVerifier
{
    public function __construct(
        protected readonly string $secret,
        protected readonly string $signatureHeader = 'X-Signature',
        protected readonly ?string $timestampHeader = 'X-Timestamp',
        protected readonly string $eventIdPath = 'id',
        protected readonly string $eventTypePath = 'type',
    ) {}

    public function verify(Request $request): bool
    {
        $signature = (string) $request->header($this->signatureHeader, '');

        if ($signature === '' || $this->secret === '') {
            return false;
        }

        if (! $this->timestampIsFresh($request)) {
            return false;
        }

        // getContent() is the bytes as received. Re-encoding the parsed JSON
        // would produce a different string and a different digest.
        $payload = $this->signingPayload($request);

        return hash_equals(hash_hmac('sha256', $payload, $this->secret), $signature);
    }

    public function externalEventId(Request $request): ?string
    {
        $value = data_get($request->json()->all(), $this->eventIdPath);

        return $value === null ? null : (string) $value;
    }

    public function eventType(Request $request): ?string
    {
        $value = data_get($request->json()->all(), $this->eventTypePath);

        return $value === null ? null : (string) $value;
    }

    protected function signingPayload(Request $request): string
    {
        $timestamp = $this->timestampHeader === null ? null : $request->header($this->timestampHeader);

        return $timestamp === null
            ? $request->getContent()
            : $timestamp.'.'.$request->getContent();
    }

    /**
     * Without this, a signature captured once stays valid forever and the
     * request can be replayed indefinitely.
     */
    protected function timestampIsFresh(Request $request): bool
    {
        if ($this->timestampHeader === null) {
            return true;
        }

        $timestamp = $request->header($this->timestampHeader);

        if ($timestamp === null) {
            return false;
        }

        $tolerance = (int) config('kavo.webhooks.inbound.tolerance_seconds', 300);

        return abs(time() - (int) $timestamp) <= $tolerance;
    }
}
