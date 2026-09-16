<?php

declare(strict_types=1);

namespace App\Modules\Platform\Observability\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The slowest queries by total time spent, from pg_stat_statements.
 *
 * Ordered by total time rather than mean by default: a 5ms query run two
 * million times costs more than a 2-second report run once a day, and is
 * usually the one worth fixing.
 */
final class SlowQueries extends Command
{
    protected $signature = 'kavo:slow-queries {--limit=15} {--by=total : total|mean|calls}';

    protected $description = 'Show the slowest queries recorded by pg_stat_statements';

    public function handle(): int
    {
        $order = match ($this->option('by')) {
            'mean' => 'mean_exec_time',
            'calls' => 'calls',
            default => 'total_exec_time',
        };

        try {
            $rows = DB::connection(config('kavo.tenancy.owner_connection'))->select(
                "SELECT calls,
                        round(total_exec_time::numeric, 1) AS total_ms,
                        round(mean_exec_time::numeric, 2) AS mean_ms,
                        rows,
                        left(regexp_replace(query, '\\s+', ' ', 'g'), 120) AS query
                 FROM pg_stat_statements
                 WHERE query NOT LIKE '%pg_stat_statements%'
                 ORDER BY {$order} DESC
                 LIMIT ?",
                [(int) $this->option('limit')]
            );
        } catch (Throwable $e) {
            $this->error('pg_stat_statements is unavailable: '.$e->getMessage());
            $this->line('Install it with deploy/sql/02-observability.sql and add it to shared_preload_libraries.');

            return self::FAILURE;
        }

        if ($rows === []) {
            $this->info('No query statistics recorded yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['calls', 'total ms', 'mean ms', 'rows', 'query'],
            array_map(static fn (object $row): array => (array) $row, $rows),
        );

        return self::SUCCESS;
    }
}
