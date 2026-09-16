<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Payments\Gateways;

use App\Modules\Platform\Billing\Payments\PaymentRail;
use App\Modules\Platform\Billing\Payments\PaymentRequest;
use App\Modules\Platform\Billing\Payments\PaymentResult;
use App\Modules\Platform\Billing\Payments\SettlementNotice;
use App\Shared\Contracts\PaymentGateway;
use App\Shared\Enums\PaymentStatus;
use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * Paymob — cards and mobile wallets.
 *
 * Even on the card rail, settlement is taken from the webhook rather than the
 * redirect: a customer can close the tab mid-3-D-Secure, and the callback
 * still arrives. Trusting the browser's return trip loses exactly the
 * payments that did go through.
 */
final readonly class PaymobGateway implements PaymentGateway
{
    public function __construct(
        private Http $http,
        private string $baseUrl,
        private string $apiKey,
        private string $integrationId,
        private string $iframeId,
        private string $hmacSecret,
    ) {}

    public function name(): string
    {
        return 'paymob';
    }

    public function supportedRails(): array
    {
        return [PaymentRail::Card, PaymentRail::Wallet];
    }

    public function supports(PaymentRail $rail): bool
    {
        return in_array($rail, $this->supportedRails(), true);
    }

    public function charge(PaymentRequest $request): PaymentResult
    {
        if (! $this->supports($request->rail)) {
            return PaymentResult::failed("Paymob does not support the {$request->rail->value} rail.");
        }

        try {
            $token = $this->authenticate();

            if ($token === null) {
                return PaymentResult::unavailable('Could not authenticate with Paymob.');
            }

            $orderId = $this->createOrder($token, $request);

            if ($orderId === null) {
                return PaymentResult::unavailable('Paymob did not return an order id.');
            }

            $paymentKey = $this->createPaymentKey($token, $orderId, $request);

            if ($paymentKey === null) {
                return PaymentResult::unavailable('Paymob did not return a payment key.');
            }
        } catch (Throwable $e) {
            return PaymentResult::unavailable($e->getMessage());
        }

        // requiresAction, not succeeded: the customer still has to complete
        // the hosted form, and money has not moved yet.
        return PaymentResult::requiresAction(
            gatewayReference: (string) $orderId,
            redirectUrl: sprintf('%s/api/acceptance/iframes/%s?payment_token=%s', rtrim($this->baseUrl, '/'), $this->iframeId, $paymentKey),
            raw: ['order_id' => $orderId],
        );
    }

    public function parseSettlement(array $payload): ?SettlementNotice
    {
        $object = $payload['obj'] ?? $payload;
        $reference = $object['order']['merchant_order_id'] ?? null;

        if ($reference === null) {
            return null;
        }

        $success = filter_var($object['success'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $pending = filter_var($object['pending'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $refunded = filter_var($object['is_refunded'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $status = match (true) {
            $refunded => PaymentStatus::Refunded,
            $success => PaymentStatus::Succeeded,
            $pending => PaymentStatus::Processing,
            default => PaymentStatus::Failed,
        };

        return new SettlementNotice(
            reference: (string) $reference,
            status: $status,
            gatewayReference: isset($object['order']['id']) ? (string) $object['order']['id'] : null,
            amountCents: isset($object['amount_cents']) ? (int) $object['amount_cents'] : null,
            // Paymob's transaction id. Unique per attempt, so a retried
            // callback maps to the same event.
            externalEventId: isset($object['id']) ? (string) $object['id'] : null,
            error: $success ? null : ($object['data']['message'] ?? null),
            raw: $payload,
        );
    }

    public function refund(string $gatewayReference, ?int $amountCents = null): PaymentResult
    {
        try {
            $token = $this->authenticate();

            if ($token === null) {
                return PaymentResult::unavailable('Could not authenticate with Paymob.');
            }

            $response = $this->http->acceptJson()->timeout(20)
                ->post(rtrim($this->baseUrl, '/').'/api/acceptance/void_refund/refund', [
                    'auth_token' => $token,
                    'transaction_id' => $gatewayReference,
                    'amount_cents' => $amountCents,
                ]);
        } catch (Throwable $e) {
            return PaymentResult::unavailable($e->getMessage());
        }

        return $response->successful()
            ? PaymentResult::succeeded($gatewayReference, (array) $response->json())
            : PaymentResult::failed((string) $response->body());
    }

    /** HMAC over Paymob's fixed field ordering — not over the raw body. */
    public function settlementSignatureIsValid(array $payload, string $signature): bool
    {
        $object = $payload['obj'] ?? [];

        $ordered = [
            $object['amount_cents'] ?? '',
            $object['created_at'] ?? '',
            $object['currency'] ?? '',
            $object['error_occured'] ?? '',
            $object['has_parent_transaction'] ?? '',
            $object['id'] ?? '',
            $object['integration_id'] ?? '',
            $object['is_3d_secure'] ?? '',
            $object['is_auth'] ?? '',
            $object['is_capture'] ?? '',
            $object['is_refunded'] ?? '',
            $object['is_standalone_payment'] ?? '',
            $object['is_voided'] ?? '',
            $object['order']['id'] ?? '',
            $object['owner'] ?? '',
            $object['pending'] ?? '',
            $object['source_data']['pan'] ?? '',
            $object['source_data']['sub_type'] ?? '',
            $object['source_data']['type'] ?? '',
            $object['success'] ?? '',
        ];

        $concatenated = implode('', array_map(
            static fn (mixed $value): string => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
            $ordered,
        ));

        return hash_equals(hash_hmac('sha512', $concatenated, $this->hmacSecret), $signature);
    }

    private function authenticate(): ?string
    {
        $response = $this->http->acceptJson()->timeout(15)
            ->post(rtrim($this->baseUrl, '/').'/api/auth/tokens', ['api_key' => $this->apiKey]);

        return $response->successful() ? $response->json('token') : null;
    }

    private function createOrder(string $token, PaymentRequest $request): ?int
    {
        $response = $this->http->acceptJson()->timeout(15)
            ->post(rtrim($this->baseUrl, '/').'/api/ecommerce/orders', [
                'auth_token' => $token,
                'delivery_needed' => false,
                'amount_cents' => $request->amount->amountCents,
                'currency' => $request->amount->currency,
                'merchant_order_id' => $request->reference,
                'items' => [],
            ]);

        return $response->successful() ? (int) $response->json('id') : null;
    }

    private function createPaymentKey(string $token, int $orderId, PaymentRequest $request): ?string
    {
        [$firstName, $lastName] = $this->splitName($request->customerName);

        $response = $this->http->acceptJson()->timeout(15)
            ->post(rtrim($this->baseUrl, '/').'/api/acceptance/payment_keys', [
                'auth_token' => $token,
                'amount_cents' => $request->amount->amountCents,
                'currency' => $request->amount->currency,
                'order_id' => $orderId,
                'integration_id' => $this->integrationId,
                'expiration' => 3600,
                'billing_data' => [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $request->customerEmail,
                    'phone_number' => $request->customerPhone,
                    // Paymob rejects empty billing fields outright, so the
                    // unused ones are sent as its documented placeholder.
                    'apartment' => 'NA', 'floor' => 'NA', 'street' => 'NA',
                    'building' => 'NA', 'shipping_method' => 'NA',
                    'postal_code' => 'NA', 'city' => 'NA', 'country' => 'EG',
                    'state' => 'NA',
                ],
            ]);

        return $response->successful() ? $response->json('token') : null;
    }

    /** @return array{0: string, 1: string} */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return [$parts[0] ?? 'Customer', count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : 'NA'];
    }
}
