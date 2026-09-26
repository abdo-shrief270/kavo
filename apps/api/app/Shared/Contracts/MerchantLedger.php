<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Modules\Platform\Billing\Payments\Money;
use App\Modules\Platform\Identity\Models\Tenant;

/**
 * What the platform owes a merchant, and why.
 *
 * The platform is the merchant of record (ADR 0001 B10): customers pay into
 * the platform's own gateway accounts, so every settled sale is a debt to the
 * shop that made it. This is where that debt is recorded.
 *
 * A vertical reports a *sale*, not a credit and a commission. How much the
 * platform keeps is platform policy — a number a commerce module has no
 * business knowing, and one that must not be re-derived differently in each
 * vertical that ships next.
 */
interface MerchantLedger
{
    /**
     * A sale settled. Records the gross owed and the platform's cut of it.
     *
     * Idempotent per source: a retried settlement or a replayed webhook credits
     * the order once. Callers do not have to remember that.
     *
     * @param  string  $sourceType  what caused this, as a slug — 'order'
     * @param  int  $sourceId  its id in whichever module owns it
     */
    public function recordSale(Tenant $tenant, Money $gross, string $sourceType, int $sourceId, string $description): void;

    /**
     * Money went back to a customer. Takes the gross off what the merchant is
     * owed and returns the commission with it — the platform earns nothing on
     * a sale that did not happen.
     */
    public function recordRefund(Tenant $tenant, Money $gross, string $sourceType, int $sourceId, string $description): void;

    /** Everything owed, less everything already paid or clawed back. */
    public function balance(Tenant $tenant): Money;

    /** What the platform would keep on a sale of this size. */
    public function commissionOn(Money $gross): Money;
}
