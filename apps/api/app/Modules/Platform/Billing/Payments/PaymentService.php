<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Payments;

use App\Modules\Platform\Billing\Models\PaymentEvent;
use App\Modules\Platform\Billing\Models\PaymentIntent;
use App\Shared\Enums\PaymentStatus;
use App\Shared\Events\PaymentSettled;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates payment intents across rails.
 *
 * Two invariants hold everywhere in here:
 *
 *  1. An intent only ever moves forward. A late or duplicated callback cannot
 *     reopen a settled payment, which is what stops a replayed webhook
 *     un-refunding an order or re-succeeding a failed one.
 *  2. Every transition is recorded in payment_events before the intent is
 *     updated, so the history explains the state rather than merely agreeing
 *     with it.
 */
final readonly class PaymentService
{
    public function __construct(
        private PaymentGatewayManager $gateways,
        private TenantContext $tenants,
    ) {}

    /**
     * Create an intent and hand it to the right gateway.
     *
     * The intent is persisted *before* the gateway is called. If the call
     * then times out, we still have a record to reconcile against — losing
     * the intent would leave a charge nobody in our system knows about.
     */
    public function charge(PaymentRequest $request): PaymentIntent
    {
        $tenant = $this->tenants->getOrFail('creating a payment');
        $gateway = $this->gateways->for($request->rail);

        $intent = PaymentIntent::create([
            'tenant_id' => $tenant->getKey(),
            'reference' => $request->reference,
            'gateway' => $gateway->name(),
            'rail' => $request->rail,
            'status' => PaymentStatus::Pending,
            'amount_cents' => $request->amount->amountCents,
            'currency' => $request->amount->currency,
            'metadata' => $request->metadata,
        ]);

        $result = $gateway->charge($request);

        $this->applyResult($intent, $result);

        return $intent->refresh();
    }

    /**
     * Apply a verified settlement from a provider callback.
     *
     * Matching is on our own reference, scoped to the tenant: a provider
     * echoes back what we sent, and two tenants may legitimately both have an
     * order-1001.
     */
    public function settle(SettlementNotice $notice, string $gatewayName): ?PaymentIntent
    {
        $intent = PaymentIntent::query()
            ->where('reference', $notice->reference)
            ->where('gateway', $gatewayName)
            ->first();

        if ($intent === null) {
            Log::warning('Settlement for an unknown payment reference', [
                'gateway' => $gatewayName,
                'reference' => $notice->reference,
            ]);

            return null;
        }

        // A settled payment stays settled. Providers retry, and out-of-order
        // callbacks are normal; neither may rewrite a terminal outcome.
        if ($intent->status->isFinal() && $notice->status !== PaymentStatus::Refunded) {
            return $intent;
        }

        // An amount that disagrees with what we asked for is never applied
        // automatically — it is either a provider bug or a tampered payload,
        // and both need a human.
        if ($notice->amountCents !== null && $notice->amountCents !== $intent->amount_cents) {
            Log::error('Settlement amount does not match the intent', [
                'reference' => $intent->reference,
                'expected_cents' => $intent->amount_cents,
                'received_cents' => $notice->amountCents,
            ]);

            $this->transition($intent, PaymentStatus::Failed, 'gateway', $notice->externalEventId, $notice->raw, 'Settlement amount mismatch.');

            return $intent->refresh();
        }

        $this->transition(
            $intent,
            $notice->status,
            'gateway',
            $notice->externalEventId,
            $notice->raw,
            $notice->error,
            $notice->gatewayReference,
        );

        return $intent->refresh();
    }

    /**
     * Close out intents whose offline window has passed.
     *
     * Without this an unpaid Fawry reference holds its reservation forever,
     * and the catalogue sells out to customers who never paid.
     *
     * @return int number of intents expired
     */
    public function expireOverdue(): int
    {
        $expired = 0;

        PaymentIntent::query()
            ->whereIn('status', [
                PaymentStatus::AwaitingOfflinePayment->value,
                PaymentStatus::RequiresAction->value,
                PaymentStatus::Pending->value,
            ])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->chunkById(100, function ($intents) use (&$expired): void {
                foreach ($intents as $intent) {
                    $this->transition($intent, PaymentStatus::Expired, 'system', null, [], 'The payment window closed without payment.');
                    $expired++;
                }
            });

        return $expired;
    }

    private function applyResult(PaymentIntent $intent, PaymentResult $result): void
    {
        $intent->forceFill(array_filter([
            'gateway_reference' => $result->gatewayReference,
            'payment_reference' => $result->paymentReference,
            'redirect_url' => $result->redirectUrl,
            'expires_at' => $result->expiresAt,
        ], static fn (mixed $value): bool => $value !== null))->save();

        $this->transition($intent, $result->status, 'gateway', null, $result->raw, $result->error, $result->gatewayReference);
    }

    /**
     * Record the transition, then move the intent.
     *
     * The event row is written first and carries a unique constraint on
     * (intent, external_event_id). A provider replaying a callback therefore
     * collides here and the intent is left untouched — the history is what
     * enforces idempotency, not a check the caller has to remember.
     */
    private function transition(
        PaymentIntent $intent,
        PaymentStatus $to,
        string $source,
        ?string $externalEventId = null,
        array $payload = [],
        ?string $error = null,
        ?string $gatewayReference = null,
    ): void {
        $from = $intent->status;

        if ($from === $to && $externalEventId === null) {
            return;
        }

        DB::transaction(function () use ($intent, $from, $to, $source, $externalEventId, $payload, $error, $gatewayReference): void {
            try {
                PaymentEvent::create([
                    'tenant_id' => $intent->tenant_id,
                    'payment_intent_id' => $intent->getKey(),
                    'from_status' => $from,
                    'to_status' => $to,
                    'source' => $source,
                    'external_event_id' => $externalEventId,
                    'payload' => $payload,
                ]);
            } catch (QueryException $e) {
                // 23505: this provider event was already applied.
                if ($e->getCode() === '23505') {
                    throw new DuplicateSettlement;
                }

                throw $e;
            }

            $intent->forceFill(array_filter([
                'status' => $to,
                'last_error' => $error,
                'gateway_reference' => $gatewayReference ?? $intent->gateway_reference,
                'settled_at' => $to === PaymentStatus::Succeeded ? now() : $intent->settled_at,
                'refunded_cents' => $to === PaymentStatus::Refunded ? $intent->amount_cents : $intent->refunded_cents,
            ], static fn (mixed $value): bool => $value !== null))->save();
        });

        if ($to->isFinal()) {
            Event::dispatch(new PaymentSettled($intent, $from, $to));
        }
    }
}
