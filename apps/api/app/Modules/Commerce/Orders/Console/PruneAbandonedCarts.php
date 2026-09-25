<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Orders\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Carts are abandoned far more often than they are checked out.
 *
 * Nothing breaks if this never runs — an expired cart is already ignored on
 * read and a fresh one is issued in its place, and carts hold no stock. What
 * breaks is the table, which otherwise only ever grows.
 *
 * Deletes through the schema owner because pruning is by definition
 * cross-tenant, and there is no tenant to bind when a scheduler runs it.
 */
final class PruneAbandonedCarts extends Command
{
    protected $signature = 'kavo:prune-carts {--days=7 : Grace period beyond a cart\'s own expiry}';

    protected $description = 'Delete carts that expired and were never checked out';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) $this->option('days'));

        $deleted = DB::connection(config('kavo.tenancy.owner_connection'))
            ->table('carts')
            ->where('expires_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$deleted} abandoned cart(s).");

        return self::SUCCESS;
    }
}
