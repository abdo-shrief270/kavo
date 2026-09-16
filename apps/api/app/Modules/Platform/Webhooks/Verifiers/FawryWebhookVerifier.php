<?php

declare(strict_types=1);

namespace App\Modules\Platform\Webhooks\Verifiers;

use App\Shared\Contracts\WebhookVerifier;
use Illuminate\Http\Request;

/**
 * Fawry settlement callbacks.
 *
 * This is the only path by which a reference payment ever becomes paid: the
 * customer settles at a kiosk with no connection to us at all, so the callback
 * is not an optimisation, it is the mechanism. Which also means a verifier
 * that rejects every genuine callback does not merely fail closed — it makes
 * the whole offline rail unable to complete a sale.
 *
 * Fawry signs with a plain SHA-256 digest over a documented concatenation
 * ending in the merchant's secure key, and sends it in the body as
 * `messageSignature`. There is no signature header and no HMAC.
 */
final class FawryWebhookVerifier implements WebhookVerifier
{
    private readonly string $secureKey;

    public function __construct()
    {
        $this->secureKey = (string) config('services.fawry.security_key', '');
    }

    public function verify(Request $request): bool
    {
        $payload = $request->json()->all();
        $presented = (string) ($payload['messageSignature'] ?? '');

        if ($presented === '' || $this->secureKey === '') {
            return false;
        }

        return hash_equals(hash('sha256', $this->signingString($payload)), mb_strtolower($presented));
    }

    /**
     * Fawry's reference plus the status it is reporting.
     *
     * The reference alone would be wrong: one payment legitimately produces a
     * PAID callback and later a REFUNDED one, and de-duplicating on the
     * reference would swallow the second.
     */
    public function externalEventId(Request $request): ?string
    {
        $payload = $request->json()->all();
        $reference = $payload['fawryRefNumber'] ?? null;

        if ($reference === null) {
            return null;
        }

        return (string) $reference.':'.mb_strtoupper((string) ($payload['orderStatus'] ?? ''));
    }

    public function eventType(Request $request): ?string
    {
        $status = $request->json()->all()['orderStatus'] ?? null;

        return $status === null ? null : (string) $status;
    }

    /**
     * Fawry's documented signing order.
     *
     * There is no timestamp to bind, so this signature does not expire.
     * Replay is stopped a layer up instead: the event id is unique per
     * provider, and a settled intent refuses to be moved again.
     *
     * @param  array<string, mixed>  $payload
     */
    private function signingString(array $payload): string
    {
        return implode('', [
            (string) ($payload['fawryRefNumber'] ?? ''),
            // v1 spells it out, v2 abbreviates. Both reach this endpoint.
            (string) ($payload['merchantRefNumber'] ?? $payload['merchantRefNum'] ?? ''),
            $this->amount($payload['paymentAmount'] ?? null),
            $this->amount($payload['orderAmount'] ?? null),
            (string) ($payload['orderStatus'] ?? ''),
            (string) ($payload['paymentMethod'] ?? ''),
            // Fawry's own spelling, typo included. Absent on cash payments,
            // where the empty string is part of the signed material rather
            // than a field to leave out.
            (string) ($payload['paymentRefrenceNumber'] ?? ''),
            $this->secureKey,
        ]);
    }

    /** Two decimals always: 50 signs as "50.00", and "50" is a mismatch. */
    private function amount(mixed $value): string
    {
        return $value === null ? '' : number_format((float) $value, 2, '.', '');
    }
}
