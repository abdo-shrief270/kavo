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
 * Fawry — reference/voucher payments.
 *
 * The rail that makes the asynchronous shape of the contract necessary.
 * Fawry reaches ~97% of Egyptian households through 300,000+ outlets, and the
 * customer pays *later*: we issue a reference number, they walk to a kiosk,
 * and settlement arrives as a webhook hours or days afterwards.
 *
 * So `charge()` returning without money is the expected outcome here, not a
 * failure. Treating it as one would cancel every Fawry order at the moment it
 * was placed — which is precisely the rewrite ADR 0001 §6 was written to
 * avoid.
 */
final readonly class FawryGateway implements PaymentGateway
{
    public function __construct(
        private Http $http,
        private string $baseUrl,
        private string $merchantCode,
        private string $securityKey,
        private int $expiryHours = 72,
    ) {}

    public function name(): string
    {
        return 'fawry';
    }

    public function supportedRails(): array
    {
        return [PaymentRail::Reference];
    }

    public function supports(PaymentRail $rail): bool
    {
        return in_array($rail, $this->supportedRails(), true);
    }

    public function charge(PaymentRequest $request): PaymentResult
    {
        if (! $this->supports($request->rail)) {
            return PaymentResult::failed("Fawry does not support the {$request->rail->value} rail.");
        }

        $expiryHours = $this->expiryHours;

        $payload = [
            'merchantCode' => $this->merchantCode,
            'merchantRefNum' => $request->reference,
            'customerName' => $request->customerName,
            'customerMobile' => $request->customerPhone,
            'customerEmail' => $request->customerEmail,
            'paymentMethod' => 'PAYATFAWRY',
            // Fawry works in major units; everything internal is minor.
            'amount' => number_format($request->amount->amountCents / 100, 2, '.', ''),
            'currencyCode' => $request->amount->currency,
            'paymentExpiry' => now()->addHours($expiryHours)->getTimestampMs(),
            'chargeItems' => [[
                'itemId' => $request->reference,
                'description' => $request->metadata['description'] ?? 'Order '.$request->reference,
                'price' => number_format($request->amount->amountCents / 100, 2, '.', ''),
                'quantity' => 1,
            ]],
        ];

        $payload['signature'] = $this->chargeSignature($request);

        try {
            $response = $this->http->acceptJson()->timeout(20)
                ->post(rtrim($this->baseUrl, '/').'/ECommerceWeb/Fawry/payments/charge', $payload);
        } catch (Throwable $e) {
            return PaymentResult::unavailable($e->getMessage());
        }

        if (! $response->successful()) {
            // 5xx and 429 may succeed on a retry; a 4xx will not.
            return $response->status() >= 500 || $response->status() === 429
                ? PaymentResult::unavailable((string) $response->body())
                : PaymentResult::failed((string) ($response->json('statusDescription') ?? $response->body()));
        }

        $body = (array) $response->json();
        $referenceNumber = $body['referenceNumber'] ?? null;

        if ($referenceNumber === null) {
            return PaymentResult::failed('Fawry accepted the charge but returned no reference number.');
        }

        return PaymentResult::awaitingOfflinePayment(
            gatewayReference: (string) $referenceNumber,
            paymentReference: (string) $referenceNumber,
            expiresAt: now()->addHours($expiryHours),
            raw: $body,
        );
    }

    public function parseSettlement(array $payload): ?SettlementNotice
    {
        $reference = $payload['merchantRefNumber'] ?? $payload['merchantRefNum'] ?? null;

        if ($reference === null) {
            return null;
        }

        $status = match (strtoupper((string) ($payload['orderStatus'] ?? ''))) {
            'PAID' => PaymentStatus::Succeeded,
            'EXPIRED' => PaymentStatus::Expired,
            'CANCELED', 'CANCELLED', 'FAILED' => PaymentStatus::Failed,
            'REFUNDED' => PaymentStatus::Refunded,
            // UNPAID means the reference exists and is still open — not news.
            default => null,
        };

        if ($status === null) {
            return null;
        }

        return new SettlementNotice(
            reference: (string) $reference,
            status: $status,
            gatewayReference: isset($payload['fawryRefNumber']) ? (string) $payload['fawryRefNumber'] : null,
            amountCents: isset($payload['paymentAmount'])
                ? (int) round(((float) $payload['paymentAmount']) * 100)
                : null,
            externalEventId: isset($payload['fawryRefNumber'])
                ? $payload['fawryRefNumber'].':'.strtoupper((string) $payload['orderStatus'])
                : null,
            raw: $payload,
        );
    }

    public function refund(string $gatewayReference, ?int $amountCents = null): PaymentResult
    {
        if ($amountCents === null) {
            return PaymentResult::failed('Fawry refunds require an explicit amount.');
        }

        try {
            $response = $this->http->acceptJson()->timeout(20)
                ->post(rtrim($this->baseUrl, '/').'/ECommerceWeb/Fawry/payments/refund', [
                    'merchantCode' => $this->merchantCode,
                    'referenceNumber' => $gatewayReference,
                    'refundAmount' => number_format($amountCents / 100, 2, '.', ''),
                    'signature' => hash('sha256', $this->merchantCode.$gatewayReference.number_format($amountCents / 100, 2, '.', '').$this->securityKey),
                ]);
        } catch (Throwable $e) {
            return PaymentResult::unavailable($e->getMessage());
        }

        return $response->successful()
            ? PaymentResult::succeeded($gatewayReference, (array) $response->json())
            : PaymentResult::failed((string) ($response->json('statusDescription') ?? $response->body()));
    }

    /** Fawry signs a fixed concatenation, not the JSON body. */
    private function chargeSignature(PaymentRequest $request): string
    {
        $amount = number_format($request->amount->amountCents / 100, 2, '.', '');

        return hash('sha256', implode('', [
            $this->merchantCode,
            $request->reference,
            $request->customerPhone,
            'PAYATFAWRY',
            $amount,
            $this->securityKey,
        ]));
    }
}
