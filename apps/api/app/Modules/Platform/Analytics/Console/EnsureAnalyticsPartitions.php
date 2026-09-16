<?php

declare(strict_types=1);

namespace App\Modules\Platform\Analytics\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Keeps partition runway ahead of ingestion.
 *
 * A DEFAULT partition exists so a missed run never loses data, but rows
 * landing there lose the pruning benefit — so this runs monthly and creates
 * partitions three months out.
 */
final class EnsureAnalyticsPartitions extends Command
{
    protected $signature = 'kavo:analytics-partitions {--months=3 : How many months ahead to create}';

    protected $description = 'Create upcoming monthly partitions for analytics_events';

    public function handle(): int
    {
        $months = max(1, (int) $this->option('months'));
        $connection = DB::connection(config('kavo.tenancy.owner_connection'));

        for ($offset = 0; $offset <= $months; $offset++) {
            $month = now()->startOfMonth()->addMonths($offset);
            $name = 'analytics_events_'.$month->format('Y_m');
            $start = $month->toDateString();
            $end = $month->copy()->addMonth()->toDateString();

            $connection->statement(
                "CREATE TABLE IF NOT EXISTS {$name} PARTITION OF analytics_events FOR VALUES FROM ('{$start}') TO ('{$end}')"
            );

            $this->line("Ensured partition {$name}");
        }

        return self::SUCCESS;
    }
}
