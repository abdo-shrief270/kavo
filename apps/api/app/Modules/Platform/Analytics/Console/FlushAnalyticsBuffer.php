<?php

declare(strict_types=1);

namespace App\Modules\Platform\Analytics\Console;

use App\Shared\Contracts\AnalyticsIngestor;
use Illuminate\Console\Command;

final class FlushAnalyticsBuffer extends Command
{
    protected $signature = 'kavo:flush-analytics';

    protected $description = 'Batch-insert buffered analytics events into Postgres';

    public function handle(AnalyticsIngestor $ingestor): int
    {
        $this->info("Wrote {$ingestor->flush()} analytics event(s).");

        return self::SUCCESS;
    }
}
