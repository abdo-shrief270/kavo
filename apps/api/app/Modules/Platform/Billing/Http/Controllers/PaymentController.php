<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Http\Controllers;

use App\Modules\Platform\Audit\Services\AuditRecorder;
use App\Modules\Platform\Billing\Models\PaymentIntent;
use App\Modules\Platform\Billing\Payments\Money;
use App\Modules\Platform\Billing\Payments\PaymentGatewayManager;
use App\Modules\Platform\Billing\Payments\PaymentRail;
use App\Modules\Platform\Billing\Payments\PaymentRequest;
use App\Modules\Platform\Billing\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class PaymentController
{
    /** Rails the customer can actually be offered, given what is configured. */
    public function rails(PaymentGatewayManager $gateways): JsonResponse
    {
        return response()->json([
            'rails' => array_map(static fn (PaymentRail $rail): array => [
                'rail' => $rail->value,
                'label' => $rail->label(),
                'offline' => $rail->isOffline(),
            ], $gateways->availableRails()),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            PaymentIntent::query()
                ->when($request->string('status')->toString(), fn ($q, string $s) => $q->where('status', $s))
                ->latest('id')
                ->paginate(30)
        );
    }

    public function show(PaymentIntent $payment): JsonResponse
    {
        return response()->json([
            'payment' => $payment,
            'customer_action' => $payment->customerAction(),
            'events' => $payment->events()->latest('id')->get(),
        ]);
    }

    public function store(Request $request, PaymentService $payments, AuditRecorder $audit): JsonResponse
    {
        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:64'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'rail' => ['required', 'string', 'in:card,wallet,reference,cod'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['required', 'email', 'max:255'],
            // Required on every rail, not just the offline ones: a reference
            // payment has no other way to reach the payer with their code,
            // and discovering that at send time fails the checkout instead
            // of the validation.
            'customer_phone' => ['required', 'string', 'max:32'],
            'metadata' => ['array'],
            'return_url' => ['nullable', 'url'],
        ]);

        $intent = $payments->charge(new PaymentRequest(
            reference: $validated['reference'],
            amount: new Money($validated['amount_cents'], strtoupper($validated['currency'])),
            rail: PaymentRail::from($validated['rail']),
            customerName: $validated['customer_name'],
            customerEmail: $validated['customer_email'],
            customerPhone: $validated['customer_phone'],
            metadata: $validated['metadata'] ?? [],
            returnUrl: $validated['return_url'] ?? null,
        ));

        $audit->record('payment.created', $intent, null, [
            'reference' => $intent->reference,
            'rail' => $intent->rail->value,
            'amount_cents' => $intent->amount_cents,
        ]);

        // 201 whatever the rail returned. An issued reference is a created
        // payment, not a failed one — the customer simply has not paid yet.
        return response()->json([
            'payment' => $intent,
            'customer_action' => $intent->customerAction(),
        ], 201);
    }

    public function refund(Request $request, PaymentIntent $payment, PaymentGatewayManager $gateways, AuditRecorder $audit): JsonResponse
    {
        $validated = $request->validate([
            'amount_cents' => ['nullable', 'integer', 'min:1', 'max:'.$payment->amount_cents],
        ]);

        if (! $payment->status->isFinal() || $payment->isFullyRefunded()) {
            return response()->json(['message' => 'This payment cannot be refunded in its current state.'], 422);
        }

        $result = $gateways->gateway($payment->gateway)
            ->refund((string) $payment->gateway_reference, $validated['amount_cents'] ?? null);

        $audit->record('payment.refunded', $payment, null, ['amount_cents' => $validated['amount_cents']]);

        return response()->json([
            'refunded' => $result->status->value === 'succeeded',
            'error' => $result->error,
        ], $result->error === null ? 200 : 422);
    }

    /** @internal Local and staging only — drives the fake gateway's callbacks. */
    public function simulateSettlement(Request $request, PaymentService $payments): JsonResponse
    {
        abort_if(app()->isProduction(), 404);

        $validated = $request->validate([
            'reference' => ['required', 'string'],
            'status' => ['required', 'string', 'in:succeeded,failed,expired,refunded'],
        ]);

        $gateway = app(PaymentGatewayManager::class)->gateway('fake');
        $notice = $gateway->parseSettlement($validated + ['event_id' => (string) Str::uuid()]);

        return response()->json(['payment' => $notice === null ? null : $payments->settle($notice, 'fake')]);
    }
}
