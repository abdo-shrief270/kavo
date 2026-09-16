<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Payments\Gateways;

use App\Modules\Platform\Billing\Payments\PaymentRail;
use App\Modules\Platform\Billing\Payments\PaymentRequest;
use App\Modules\Platform\Billing\Payments\PaymentResult;
use App\Modules\Platform\Billing\Payments\SettlementNotice;
use App\Shared\Contracts\PaymentGateway;
use App\Shared\Enums\PaymentStatus;
use Illuminate\Support\Str;

/**
 * Local and test gateway. Supports every rail so the full lifecycle —
 * including the offline one that is hardest to exercise against a sandbox —
 * can be driven without credentials.
 *
 * Behaviour is chosen by the request metadata (`fake_outcome`) rather than at
 * random, so a test asserting a decline is not occasionally a test asserting
 * a success.
 */
final class FakeGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'fake';
    }

    public function supportedRails(): array
    {
        return PaymentRail::cases();
    }

    public function supports(PaymentRail $rail): bool
    {
        return true;
    }

    public function charge(PaymentRequest $request): PaymentResult
    {
        $outcome = $request->metadata['fake_outcome'] ?? null;

        return match ($outcome) {
            'declined' => PaymentResult::failed('Card declined by issuer.'),
            'unavailable' => PaymentResult::unavailable('Gateway timed out.'),
            default => $this->outcomeForRail($request),
        };
    }

    public function parseSettlement(array $payload): ?SettlementNotice
    {
        if (! isset($payload['reference'], $payload['status'])) {
            return null;
        }

        $status = PaymentStatus::tryFrom((string) $payload['status']);

        if ($status === null) {
            return null;
        }

        return new SettlementNotice(
            reference: (string) $payload['reference'],
            status: $status,
            gatewayReference: $payload['gateway_reference'] ?? null,
            amountCents: isset($payload['amount_cents']) ? (int) $payload['amount_cents'] : null,
            externalEventId: $payload['event_id'] ?? null,
            raw: $payload,
        );
    }

    public function refund(string $gatewayReference, ?int $amountCents = null): PaymentResult
    {
        return PaymentResult::succeeded($gatewayReference, ['refunded_cents' => $amountCents]);
    }

    private function outcomeForRail(PaymentRequest $request): PaymentResult
    {
        $gatewayReference = 'fake_'.Str::lower(Str::random(12));

        return match ($request->rail) {
            // Mirrors the real rails: offline ones leave without money.
            PaymentRail::Reference, PaymentRail::CashOnDelivery => PaymentResult::awaitingOfflinePayment(
                gatewayReference: $gatewayReference,
                paymentReference: (string) random_int(1_000_000_000, 9_999_999_999),
                expiresAt: now()->addDays(3),
            ),
            PaymentRail::Wallet => PaymentResult::requiresAction(
                $gatewayReference,
                'https://fake.gateway.test/wallet/'.$gatewayReference,
            ),
            PaymentRail::Card => PaymentResult::succeeded($gatewayReference),
        };
    }
}
