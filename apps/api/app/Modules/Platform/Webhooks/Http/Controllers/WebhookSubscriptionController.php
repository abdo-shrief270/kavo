<?php

declare(strict_types=1);

namespace App\Modules\Platform\Webhooks\Http\Controllers;

use App\Modules\Platform\Audit\Services\AuditRecorder;
use App\Modules\Platform\Webhooks\Models\WebhookDelivery;
use App\Modules\Platform\Webhooks\Models\WebhookSubscription;
use App\Modules\Platform\Webhooks\Rules\PubliclyRoutableUrl;
use App\Modules\Platform\Webhooks\Services\WebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class WebhookSubscriptionController
{
    public function index(): JsonResponse
    {
        return response()->json(['subscriptions' => WebhookSubscription::query()->latest()->get()]);
    }

    public function store(Request $request, AuditRecorder $audit): JsonResponse
    {
        $validated = $request->validate([
            // `url:https` only describes the string. The rule resolves the
            // host as well, because an https URL whose name points at
            // 169.254.169.254 is still an internal fetch we would make on the
            // tenant's behalf and hand the response back to them.
            'url' => ['required', 'url:https', 'max:2048', app(PubliclyRoutableUrl::class)],
            'event_types' => ['required', 'array', 'min:1'],
            'event_types.*' => ['string', 'max:64'],
        ]);

        $secret = Str::random(48);

        $subscription = WebhookSubscription::create([
            'url' => $validated['url'],
            'secret' => $secret,
            'event_types' => $validated['event_types'],
            'is_active' => true,
        ]);

        $audit->record('webhook_subscription.created', $subscription);

        // The only time the secret is returned. Storing it retrievable would
        // make a dashboard read enough to forge signed payloads.
        return response()->json([
            'subscription' => $subscription,
            'secret' => $secret,
        ], 201);
    }

    public function deliveries(Request $request): JsonResponse
    {
        $deliveries = WebhookDelivery::query()
            ->when($request->string('status')->toString(), fn ($q, string $s) => $q->where('status', $s))
            ->latest('id')
            ->paginate(50);

        return response()->json($deliveries);
    }

    /** Manual replay from the dashboard — failures must never be a dead end. */
    public function retry(WebhookDelivery $delivery, WebhookDispatcher $dispatcher, AuditRecorder $audit): JsonResponse
    {
        $dispatcher->retry($delivery);
        $audit->record('webhook_delivery.retried', $delivery);

        return response()->json(['delivery' => $delivery->fresh()]);
    }

    public function destroy(WebhookSubscription $subscription, AuditRecorder $audit): JsonResponse
    {
        $audit->record('webhook_subscription.deleted', $subscription);
        $subscription->delete();

        return response()->json(['message' => 'Subscription removed.']);
    }
}
