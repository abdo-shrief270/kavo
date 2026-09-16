<?php

declare(strict_types=1);

namespace App\Modules\Platform\Notifications\Jobs;

use App\Modules\Platform\Identity\Models\Tenant;
use App\Modules\Platform\Notifications\Models\NotificationDelivery;
use App\Shared\Contracts\WhatsAppGateway;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantDatabaseSession;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\RateLimiter;

final class SendWhatsAppNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300, 900];

    /**
     * The tenant travels with the job rather than being read from ambient
     * state. A worker is long-lived and handles many tenants, so a job that
     * relied on whatever context was last set would eventually act as the
     * wrong one.
     *
     * @param  array<string, mixed>  $variables
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly string $recipient,
        public readonly string $templateCode,
        public readonly array $variables = [],
        public readonly string $eventType = 'generic',
        public readonly string $language = 'en',
    ) {}

    public function handle(
        WhatsAppGateway $gateway,
        TenantContext $context,
        TenantDatabaseSession $session,
    ): void {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        // runBound, not bind/clear: dispatched from a request and run inline
        // on the sync queue, clearing would strand the caller's tenant.
        $context->runAs($tenant, function () use ($gateway, $session, $tenant): void {
            $session->runBound($tenant->getKey(), fn () => $this->send($gateway, $tenant));
        });
    }

    private function send(WhatsAppGateway $gateway, Tenant $tenant): void
    {
        // Meta enforces per-number messaging limits. Rate limiting per tenant
        // stops one noisy tenant consuming a shared allowance and getting
        // every other tenant throttled.
        $limiterKey = 'whatsapp:tenant:'.$tenant->getKey();

        if (RateLimiter::tooManyAttempts($limiterKey, 60)) {
            $this->release(RateLimiter::availableIn($limiterKey));

            return;
        }

        RateLimiter::hit($limiterKey, 60);

        $delivery = NotificationDelivery::create([
            'tenant_id' => $tenant->getKey(),
            'channel' => 'whatsapp',
            'event_type' => $this->eventType,
            'recipient' => $this->recipient,
            'template_code' => $this->templateCode,
            'status' => 'queued',
            'payload' => $this->variables,
            'attempts' => $this->attempts(),
        ]);

        $result = $gateway->sendTemplate($this->recipient, $this->templateCode, $this->variables, $this->language);

        if ($result->accepted) {
            $delivery->update([
                'status' => 'sent',
                'provider_message_id' => $result->messageId,
                'sent_at' => now(),
            ]);

            return;
        }

        $delivery->update([
            'status' => 'failed',
            'error' => $result->error,
            'failed_at' => now(),
        ]);

        // A rejection is permanent — an unapproved template or a bad number
        // fails identically on every retry, so burning four more attempts on
        // it only delays the failure being visible.
        if (! $result->retryable) {
            $this->fail($result->error ?? 'WhatsApp send rejected.');

            return;
        }

        throw new \RuntimeException('WhatsApp send failed: '.$result->error);
    }
}
