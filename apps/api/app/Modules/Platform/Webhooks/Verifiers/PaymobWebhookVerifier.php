<?php

declare(strict_types=1);

namespace App\Modules\Platform\Webhooks\Verifiers;

use App\Shared\Contracts\WebhookVerifier;
use Illuminate\Http\Request;

/**
 * Paymob transaction callbacks.
 *
 * Paymob does not sign the raw body and does not use a signature header. It
 * appends `?hmac=` to the callback URL and computes it as HMAC-SHA512 over a
 * fixed, documented concatenation of transaction fields, in their order and
 * not the payload's. A generic raw-body HMAC therefore rejects every genuine
 * callback — which fails closed, but means card payments can never settle.
 *
 * Note for the Fawry rail: reference payments settle hours or days after the
 * order is placed, so settlement arrives as a callback rather than as a
 * response to our own call. That is why the payment interface models an
 * awaiting_offline_payment state rather than only card authorise/capture.
 */
final class PaymobWebhookVerifier implements WebhookVerifier
{
    /**
     * Paymob's signing order. Alphabetical by field name as they document it,
     * which is not the order the payload arrives in and not an order worth
     * inferring — it is copied, deliberately.
     */
    private const SIGNED_FIELDS = [
        'amount_cents',
        'created_at',
        'currency',
        'error_occured',
        'has_parent_transaction',
        'id',
        'integration_id',
        'is_3d_secure',
        'is_auth',
        'is_capture',
        'is_refunded',
        'is_standalone_payment',
        'is_voided',
        'order.id',
        'owner',
        'pending',
        'source_data.pan',
        'source_data.sub_type',
        'source_data.type',
        'success',
    ];

    private readonly string $secret;

    public function __construct()
    {
        $this->secret = (string) config('services.paymob.hmac_secret', '');
    }

    public function verify(Request $request): bool
    {
        // Query string first, because that is where Paymob puts it. The
        // header is accepted too so a callback can be replayed by hand
        // without reconstructing the URL.
        $presented = (string) ($request->query('hmac') ?? $request->header('X-Paymob-Signature', ''));

        if ($presented === '' || $this->secret === '') {
            return false;
        }

        $expected = hash_hmac('sha512', $this->signingString($request->json()->all()), $this->secret);

        return hash_equals($expected, mb_strtolower($presented));
    }

    public function externalEventId(Request $request): ?string
    {
        $id = data_get($request->json()->all(), 'obj.id');

        return $id === null ? null : (string) $id;
    }

    public function eventType(Request $request): ?string
    {
        $type = data_get($request->json()->all(), 'type');

        return $type === null ? null : (string) $type;
    }

    /** @param array<string, mixed> $payload */
    private function signingString(array $payload): string
    {
        $object = data_get($payload, 'obj', []);

        $values = array_map(
            fn (string $field): string => $this->stringify(data_get($object, $field)),
            self::SIGNED_FIELDS,
        );

        return implode('', $values);
    }

    private function stringify(mixed $value): string
    {
        // JSON booleans have to go back to the lowercase spelling Paymob
        // signed; PHP would render them as '1' and ''.
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return $value === null ? '' : (string) $value;
    }
}
