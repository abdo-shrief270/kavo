<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Platform\Billing\Payments\PaymentRail;
use App\Modules\Platform\Billing\Payments\PaymentRequest;
use App\Modules\Platform\Billing\Payments\PaymentResult;
use App\Modules\Platform\Billing\Payments\SettlementNotice;
use App\Shared\Contracts\PaymentGateway;

/**
 * A gateway that always says no.
 *
 * FakeGateway takes its outcome from request metadata, which checkout does not
 * forward — deliberately, since a storefront must not be able to choose
 * whether its own payment succeeds. So a declined card is driven the way
 * production would drive it: by configuring the rail to a provider that
 * declines, and letting the real charge path run all the way through
 * PaymentSettled to the stock release.
 */
final class DecliningGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'declining';
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
        return PaymentResult::failed('Card declined by issuer.');
    }

    public function parseSettlement(array $payload): ?SettlementNotice
    {
        return null;
    }

    public function refund(string $gatewayReference, ?int $amountCents = null): PaymentResult
    {
        return PaymentResult::failed('Nothing to refund.');
    }
}
