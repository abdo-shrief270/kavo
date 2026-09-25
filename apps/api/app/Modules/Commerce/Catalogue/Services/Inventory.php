<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Catalogue\Services;

use Illuminate\Support\Facades\DB;

/**
 * The three things that ever happen to stock, each as one atomic statement.
 *
 * Every method here is a single conditional UPDATE rather than a read, a
 * decision in PHP and a write. That is not a performance choice. Two shoppers
 * reaching for the last shirt at the same instant both read "1 available", and
 * a check made in PHP passes for both of them; a check made in the WHERE
 * clause passes for exactly one, because Postgres serialises the two updates
 * on the row. The CHECK constraint on product_variants is the second line of
 * defence behind it, and should be unreachable.
 *
 * Untracked variants — made to order, digital, unlimited — are handled in the
 * same statement rather than by an early return on a PHP-side flag. A flag
 * read from a model loaded seconds ago is a stale read, and a stale read that
 * says "untracked" skips the availability check entirely.
 *
 * All four tables involved are behind row-level security, so these statements
 * are still tenant-scoped despite bypassing Eloquent: with no tenant bound
 * they match nothing at all rather than matching everything.
 */
final readonly class Inventory
{
    /**
     * Hold stock against an unpaid order.
     *
     * @return bool false when there is not enough available, in which case
     *              nothing was changed
     */
    public function reserve(int $variantId, int $quantity): bool
    {
        return $this->apply(
            <<<'SQL'
                UPDATE product_variants
                   SET stock_reserved = stock_reserved + CASE WHEN track_inventory THEN ? ELSE 0 END,
                       updated_at = now()
                 WHERE id = ?
                   AND (NOT track_inventory OR stock_on_hand - stock_reserved >= ?)
            SQL,
            [$quantity, $variantId, $quantity],
        );
    }

    /**
     * Give a reservation back to the catalogue. Payment expired, failed, or
     * the order was cancelled.
     */
    public function release(int $variantId, int $quantity): bool
    {
        return $this->apply(
            <<<'SQL'
                UPDATE product_variants
                   SET stock_reserved = stock_reserved - CASE WHEN track_inventory THEN ? ELSE 0 END,
                       updated_at = now()
                 WHERE id = ?
                   AND (NOT track_inventory OR stock_reserved >= ?)
            SQL,
            [$quantity, $variantId, $quantity],
        );
    }

    /**
     * Turn a reservation into a sale: the goods leave the shelf.
     *
     * Both counters move together. Decrementing on_hand without also clearing
     * the reservation would hold the same units twice and quietly shrink the
     * catalogue with every paid order.
     */
    public function commit(int $variantId, int $quantity): bool
    {
        return $this->apply(
            <<<'SQL'
                UPDATE product_variants
                   SET stock_on_hand = stock_on_hand - CASE WHEN track_inventory THEN ? ELSE 0 END,
                       stock_reserved = stock_reserved - CASE WHEN track_inventory THEN ? ELSE 0 END,
                       updated_at = now()
                 WHERE id = ?
                   AND (NOT track_inventory OR (stock_reserved >= ? AND stock_on_hand >= ?))
            SQL,
            [$quantity, $quantity, $variantId, $quantity, $quantity],
        );
    }

    /** @param list<mixed> $bindings */
    private function apply(string $sql, array $bindings): bool
    {
        return DB::update($sql, $bindings) === 1;
    }
}
