<?php

declare(strict_types=1);

namespace App\Modules\Platform\Entitlements\Results;

/**
 * Outcome of metering an action.
 *
 * `allowed` and `overLimit` are separate on purpose: a plan with
 * allow_and_bill overage is over its limit and still permitted to proceed,
 * and the caller needs to be able to tell those apart.
 */
final readonly class ConsumeResult
{
    private function __construct(
        public bool $allowed,
        public bool $overLimit,
        public string $metric,
        public int $used,
        public ?int $limit,
        public string $overageBehavior,
    ) {}

    public static function allowed(string $metric, int $used, ?int $limit, string $behavior = 'block'): self
    {
        return new self(true, false, $metric, $used, $limit, $behavior);
    }

    public static function overage(string $metric, int $used, ?int $limit, string $behavior): self
    {
        // block is the only behaviour that refuses the action; soft_warn and
        // allow_and_bill let it through and signal through overLimit instead.
        return new self($behavior !== 'block', true, $metric, $used, $limit, $behavior);
    }

    public function blocked(): bool
    {
        return ! $this->allowed;
    }

    public function remaining(): ?int
    {
        return $this->limit === null ? null : max(0, $this->limit - $this->used);
    }

    /** Fraction of the allowance consumed, or null when unlimited. */
    public function percentUsed(): ?float
    {
        if ($this->limit === null || $this->limit === 0) {
            return null;
        }

        return round(($this->used / $this->limit) * 100, 2);
    }
}
