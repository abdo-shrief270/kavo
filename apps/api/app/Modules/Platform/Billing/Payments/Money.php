<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Payments;

use InvalidArgumentException;

/**
 * Minor units only. Floats for money are a rounding bug waiting for volume,
 * and every gateway on this list speaks piastres/cents anyway.
 */
final readonly class Money
{
    public function __construct(
        public int $amountCents,
        public string $currency = 'EGP',
    ) {
        if ($amountCents < 0) {
            throw new InvalidArgumentException('A payment amount cannot be negative.');
        }

        if (strlen($currency) !== 3) {
            throw new InvalidArgumentException("Currency must be a 3-letter ISO code, got [{$currency}].");
        }
    }

    public static function egp(int $cents): self
    {
        return new self($cents, 'EGP');
    }

    public function format(): string
    {
        return number_format($this->amountCents / 100, 2).' '.$this->currency;
    }

    public function isZero(): bool
    {
        return $this->amountCents === 0;
    }
}
