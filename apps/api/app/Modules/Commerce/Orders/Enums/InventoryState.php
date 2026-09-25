<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Orders\Enums;

/**
 * What an order's stock is currently doing.
 *
 * Tracked on the order rather than inferred from its status because
 * settlements arrive more than once. Providers retry callbacks, an expiry
 * sweep can race a late payment, and a merchant can cancel an order in the
 * same minute it settles. Reading "is it paid?" to decide whether to decrement
 * stock answers the wrong question — the right one is "has this order's stock
 * already been accounted for?", and only a recorded state can answer it.
 */
enum InventoryState: string
{
    /** Held against the order, not yet taken off the shelf. */
    case Reserved = 'reserved';

    /** Paid for and taken off the shelf. Terminal. */
    case Committed = 'committed';

    /** Given back to the catalogue. Terminal. */
    case Released = 'released';

    public function isSettled(): bool
    {
        return $this !== self::Reserved;
    }
}
