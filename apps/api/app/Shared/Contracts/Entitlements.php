<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Modules\Platform\Entitlements\Results\ConsumeResult;
use App\Modules\Platform\Identity\Models\Tenant;

/**
 * Every vertical asks the platform the same two questions: may this tenant
 * use this feature, and has it used up its allowance. This is that contract.
 *
 * Consumers depend on this interface, never on the implementation, so the
 * metering strategy behind it can change without touching callers.
 */
interface Entitlements
{
    /** Feature gate: is this capability included in the tenant's plan? */
    public function can(Tenant $tenant, string $feature): bool;

    /** Meter usage. The result says whether the caller may proceed. */
    public function consume(Tenant $tenant, string $metric, int $amount = 1): ConsumeResult;

    /**
     * Give allowance back.
     *
     * Metrics come in two shapes and the distinction matters. A *flow* metric
     * such as `orders` counts events in a period and never decreases —
     * cancelling an order does not un-place it. A *stock* metric such as
     * `storage_mb` measures what is currently held, so deleting a file must
     * return the allowance or the tenant is billed forever for bytes that no
     * longer exist.
     *
     * Only call this for stock metrics.
     */
    public function release(Tenant $tenant, string $metric, int $amount = 1): void;

    /** Remaining allowance, or null when the plan grants unlimited use. */
    public function remaining(Tenant $tenant, string $metric): ?int;

    /** Current period usage for a metric. */
    public function used(Tenant $tenant, string $metric): int;

    /** Drop cached entitlements for a tenant, e.g. after a plan change. */
    public function flush(Tenant $tenant): void;
}
