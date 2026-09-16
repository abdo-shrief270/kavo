<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Deep dependency check, deliberately separate from Laravel's `/up`.
 *
 * `/up` answers "is this release serving requests?" and is what the deploy
 * script gates rollback on. This answers "are its dependencies healthy?" and
 * is what uptime monitoring alerts on.
 *
 * Keeping them apart matters: if rollback were gated on this, a momentary
 * Redis blip would roll back a perfectly good deploy.
 */
final class HealthController
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('SELECT 1')),
            'redis' => $this->check(fn () => Redis::connection()->ping()),
            'queue' => $this->queueDepth(),
            'reverb' => $this->reverbReachable(),
        ];

        $healthy = ! in_array(false, array_column($checks, 'ok'), true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'release' => config('app.release'),
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    /** @return array{ok: bool, error?: string} */
    private function check(callable $probe): array
    {
        try {
            $probe();

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array{ok: bool, depth?: int, error?: string} */
    private function queueDepth(): array
    {
        try {
            $depth = (int) Redis::connection()->llen('queues:default');

            // A backlog is not an outage, but it is the thing that turns into
            // one, so it degrades rather than fails.
            return ['ok' => $depth < 10_000, 'depth' => $depth];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array{ok: bool, error?: string} */
    private function reverbReachable(): array
    {
        $host = (string) config('reverb.servers.reverb.host', '127.0.0.1');
        $port = (int) config('reverb.servers.reverb.port', 8080);

        $socket = @fsockopen($host, $port, $errno, $errstr, 2);

        if ($socket === false) {
            return ['ok' => false, 'error' => $errstr ?: 'unreachable'];
        }

        fclose($socket);

        return ['ok' => true];
    }
}
