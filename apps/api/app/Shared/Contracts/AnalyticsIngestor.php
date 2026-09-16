<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Behind an interface because ingestion is the most likely part of Phase 0 to
 * move: a Redis-buffered Postgres writer today, potentially a dedicated store
 * once volume justifies one. Callers should never know which.
 */
interface AnalyticsIngestor
{
    /** @param array<string, mixed> $properties */
    public function record(string $eventName, array $properties = [], ?int $tenantId = null, ?int $userId = null): void;

    /** Move anything buffered into durable storage. */
    public function flush(): int;
}
