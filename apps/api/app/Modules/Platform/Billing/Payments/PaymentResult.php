<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Payments;

use App\Shared\Enums\PaymentStatus;
use Illuminate\Support\Carbon;

/**
 * A gateway's answer to "collect this".
 *
 * Deliberately not a boolean. Three of the four outcomes are neither success
 * nor failure: a card may need 3-D Secure, a wallet may need a redirect, and
 * a reference payment is *expected* to leave without money having moved. Code
 * that treats "not succeeded" as "failed" would cancel every Fawry order the
 * moment it was placed.
 */
final readonly class PaymentResult
{
    private function __construct(
        public PaymentStatus $status,
        public ?string $gatewayReference = null,
        public ?string $redirectUrl = null,
        public ?string $paymentReference = null,
        public ?Carbon $expiresAt = null,
        public ?string $error = null,
        public bool $retryable = false,
        /** @var array<string, mixed> */
        public array $raw = [],
    ) {}

    /** Money moved. Only cards and wallets can reach this synchronously. */
    public static function succeeded(string $gatewayReference, array $raw = []): self
    {
        return new self(PaymentStatus::Succeeded, $gatewayReference, raw: $raw);
    }

    /** The customer must complete a step — 3-D Secure, a wallet prompt. */
    public static function requiresAction(string $gatewayReference, string $redirectUrl, array $raw = []): self
    {
        return new self(PaymentStatus::RequiresAction, $gatewayReference, redirectUrl: $redirectUrl, raw: $raw);
    }

    /**
     * A reference was issued. This is a *successful* call: the customer now
     * has a code to pay at an outlet, and settlement will arrive later by
     * webhook. The expiry is the window before the order should be released.
     */
    public static function awaitingOfflinePayment(
        string $gatewayReference,
        string $paymentReference,
        Carbon $expiresAt,
        array $raw = [],
    ): self {
        return new self(
            PaymentStatus::AwaitingOfflinePayment,
            $gatewayReference,
            paymentReference: $paymentReference,
            expiresAt: $expiresAt,
            raw: $raw,
        );
    }

    /** Declined, invalid card, insufficient funds — retrying will not help. */
    public static function failed(string $error, array $raw = []): self
    {
        return new self(PaymentStatus::Failed, error: $error, raw: $raw);
    }

    /** Timeout, 5xx, rate limit. The same call may succeed shortly. */
    public static function unavailable(string $error, array $raw = []): self
    {
        return new self(PaymentStatus::Failed, error: $error, retryable: true, raw: $raw);
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /** Does the customer have something left to do or pay? */
    public function needsCustomerAction(): bool
    {
        return $this->redirectUrl !== null || $this->paymentReference !== null;
    }
}
