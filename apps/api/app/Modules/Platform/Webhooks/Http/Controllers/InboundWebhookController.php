<?php

declare(strict_types=1);

namespace App\Modules\Platform\Webhooks\Http\Controllers;

use App\Modules\Platform\Webhooks\Jobs\ProcessInboundWebhook;
use App\Modules\Platform\Webhooks\Models\WebhookEvent;
use App\Shared\Contracts\WebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Does the minimum and returns fast: verify, persist, queue, 200.
 *
 * Providers retry aggressively on a slow or failed response, so any real work
 * here would turn latency into duplicate deliveries. The endpoint stays under
 * ~100ms; the queued job does the thinking.
 */
final class InboundWebhookController
{
    public function __invoke(Request $request, string $provider): JsonResponse
    {
        $verifier = $this->verifierFor($provider);

        if ($verifier === null) {
            return response()->json(['message' => 'Unknown provider.'], 404);
        }

        if (! $verifier->verify($request)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $externalId = $verifier->externalEventId($request);

        // No id means no idempotency key, and processing an event that cannot
        // be de-duplicated risks acting on it twice.
        if ($externalId === null) {
            return response()->json(['message' => 'Missing event id.'], 422);
        }

        // ON CONFLICT DO NOTHING rather than catching a unique violation.
        // In Postgres a failed statement aborts the entire surrounding
        // transaction, so relying on the exception would break the moment
        // this ran inside one — and control flow through exceptions is worse
        // than an insert that simply reports whether it wrote a row.
        $inserted = DB::table('webhook_events')->insertOrIgnore([
            'provider' => $provider,
            'external_event_id' => $externalId,
            'event_type' => $verifier->eventType($request),
            'payload' => json_encode($request->json()->all(), JSON_THROW_ON_ERROR),
            'signature_valid' => true,
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // A retry of an event already received. Acknowledging stops the
        // provider's retry loop; the original job owns processing it once.
        if ($inserted === 0) {
            return response()->json(['message' => 'Already received.'], 200);
        }

        $event = WebhookEvent::query()
            ->where('provider', $provider)
            ->where('external_event_id', $externalId)
            ->firstOrFail();

        ProcessInboundWebhook::dispatch($event->id);

        return response()->json(['message' => 'Accepted.'], 202);
    }

    private function verifierFor(string $provider): ?WebhookVerifier
    {
        $verifiers = config('kavo.webhooks.verifiers', []);

        if (! array_key_exists($provider, $verifiers)) {
            return null;
        }

        return app($verifiers[$provider]);
    }
}
