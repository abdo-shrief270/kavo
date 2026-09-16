<?php

declare(strict_types=1);

namespace App\Modules\Platform\Analytics\Services;

use App\Shared\Contracts\AnalyticsIngestor;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\DB;

/**
 * Buffers events in Redis and batch-inserts them.
 *
 * An analytics write is fire-and-forget by nature, and one INSERT per page
 * view would put the busiest write path in the system on the same connection
 * pool serving checkout. Buffering keeps it off the request's critical path.
 *
 * Writes go through the owner connection because the batch spans tenants and
 * the flush runs with no tenant bound.
 */
final class BufferedAnalyticsIngestor implements AnalyticsIngestor
{
    private const BUFFER_KEY = 'analytics:buffer';

    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly TenantContext $context,
    ) {}

    public function record(string $eventName, array $properties = [], ?int $tenantId = null, ?int $userId = null): void
    {
        $payload = json_encode([
            'tenant_id' => $tenantId ?? $this->context->id(),
            'user_id' => $userId ?? auth()->id(),
            'product' => $this->context->get()?->product?->value,
            'event_name' => $eventName,
            'properties' => $properties,
            'occurred_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        $this->redis->connection()->rpush(self::BUFFER_KEY, [$payload]);
    }

    public function flush(): int
    {
        $connection = $this->redis->connection();
        $written = 0;

        while (true) {
            // LPOP with a count drains atomically, so a concurrent flush
            // cannot take the same rows and double-insert them.
            $batch = (array) $connection->lpop(self::BUFFER_KEY, self::BATCH_SIZE);

            if ($batch === []) {
                break;
            }

            $rows = [];

            foreach ($batch as $entry) {
                $decoded = json_decode((string) $entry, true);

                if (! is_array($decoded)) {
                    continue;
                }

                $rows[] = [
                    'tenant_id' => $decoded['tenant_id'],
                    'user_id' => $decoded['user_id'],
                    'product' => $decoded['product'],
                    'event_name' => $decoded['event_name'],
                    'properties' => json_encode($decoded['properties'], JSON_THROW_ON_ERROR),
                    'occurred_at' => $decoded['occurred_at'],
                    'created_at' => now(),
                ];
            }

            if ($rows !== []) {
                DB::connection(config('kavo.tenancy.owner_connection'))
                    ->table('analytics_events')
                    ->insert($rows);

                $written += count($rows);
            }

            if (count($batch) < self::BATCH_SIZE) {
                break;
            }
        }

        return $written;
    }
}
