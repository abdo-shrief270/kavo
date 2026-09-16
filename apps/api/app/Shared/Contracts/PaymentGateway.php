<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Modules\Platform\Billing\Payments\PaymentRail;
use App\Modules\Platform\Billing\Payments\PaymentRequest;
use App\Modules\Platform\Billing\Payments\PaymentResult;
use App\Modules\Platform\Billing\Payments\SettlementNotice;

/**
 * One interface over rails that behave nothing alike.
 *
 * ADR 0001 §6 flagged this as the decision most likely to be got wrong:
 * model only card authorise/capture, and Fawry — which reaches ~97% of
 * Egyptian households and settles hours or days later at a kiosk — becomes a
 * rewrite rather than an implementation. So the contract is shaped around the
 * asynchronous case, and the synchronous one is treated as the special case
 * that happens to finish immediately.
 *
 * Three consequences run through every method below:
 *
 *  - `charge()` returning without money is a normal outcome, not an error.
 *  - Settlement arrives through `parseSettlement()` on a webhook we receive,
 *    never as the return value of a call we made.
 *  - Every open intent has an expiry, because an offline reference nobody
 *    pays must eventually release the order it is holding.
 */
interface PaymentGateway
{
    public function name(): string;

    /** @return array<int, PaymentRail> */
    public function supportedRails(): array;

    public function supports(PaymentRail $rail): bool;

    /**
     * Begin collection.
     *
     * May return succeeded, requires-action, awaiting-offline-payment or
     * failed. Only the first means money has moved.
     */
    public function charge(PaymentRequest $request): PaymentResult;

    /**
     * Interpret a verified provider callback.
     *
     * Returns null when the payload is not a settlement this gateway
     * recognises — a template status change, a test ping — so the caller can
     * acknowledge it without inventing a payment event.
     *
     * @param  array<string, mixed>  $payload
     */
    public function parseSettlement(array $payload): ?SettlementNotice;

    /**
     * Refund, fully or partially.
     *
     * Not every rail can: cash on delivery has no gateway-side money to send
     * back, so implementations may legitimately fail this.
     */
    public function refund(string $gatewayReference, ?int $amountCents = null): PaymentResult;
}
