<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Http\Middleware;

use App\Modules\Platform\Billing\Models\IdempotencyKey;
use App\Shared\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes a write endpoint safe to retry.
 *
 * ADR 0001 §10 flagged this as missing: webhook idempotency was handled, but
 * the same property is needed for a merchant's own writes. Placing an order
 * over a flaky mobile connection is the normal case in this market, and a
 * retry that creates a second order — or a second charge — is the expensive
 * kind of bug.
 *
 * Semantics:
 *  - Same key, same body  → the first response is replayed, nothing re-runs.
 *  - Same key, *different* body → 422. That is a client bug, and replaying
 *    the first response would hide it behind a success.
 *  - Key in flight → 409, so two concurrent retries cannot both proceed.
 */
final class EnsureIdempotency
{
    private const RETENTION_HOURS = 24;

    public function __construct(private readonly TenantContext $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        // Optional by design. Requiring it would break every existing client
        // the day it shipped; endpoints that must have one validate for it.
        if ($key === null || $key === '') {
            return $next($request);
        }

        $tenant = $this->tenants->getOrFail('applying idempotency');
        $hash = hash('sha256', $request->getContent());

        $existing = IdempotencyKey::query()->where('key', $key)->first();

        if ($existing !== null) {
            if ($existing->request_hash !== $hash) {
                return response()->json([
                    'message' => 'This Idempotency-Key was already used with a different request body.',
                ], 422);
            }

            if ($existing->hasResponse()) {
                return response()->json($existing->response_body, $existing->response_status)
                    ->header('Idempotent-Replay', 'true');
            }

            if (! $existing->isStale()) {
                return response()->json([
                    'message' => 'A request with this Idempotency-Key is still in flight.',
                ], 409);
            }

            $existing->update(['locked_at' => now()]);
        } else {
            try {
                IdempotencyKey::create([
                    'tenant_id' => $tenant->getKey(),
                    'key' => $key,
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'request_hash' => $hash,
                    'locked_at' => now(),
                    'expires_at' => now()->addHours(self::RETENTION_HOURS),
                ]);
            } catch (QueryException $e) {
                // Two retries raced to claim the key; the loser waits.
                if ($e->getCode() === '23505') {
                    return response()->json([
                        'message' => 'A request with this Idempotency-Key is still in flight.',
                    ], 409);
                }

                throw $e;
            }
        }

        $response = $next($request);

        $this->remember($key, $response);

        return $response;
    }

    /**
     * Only successful responses are stored. A 500 should be retryable — the
     * whole point is that the caller can try again — and replaying a failure
     * forever would be worse than the duplicate it prevents.
     */
    private function remember(string $key, Response $response): void
    {
        if ($response->getStatusCode() >= 500) {
            IdempotencyKey::query()->where('key', $key)->delete();

            return;
        }

        $body = json_decode((string) $response->getContent(), true);

        IdempotencyKey::query()->where('key', $key)->update([
            'response_status' => $response->getStatusCode(),
            'response_body' => is_array($body) ? $body : null,
            'updated_at' => now(),
        ]);
    }
}
