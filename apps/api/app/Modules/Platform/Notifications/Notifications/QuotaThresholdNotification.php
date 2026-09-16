<?php

declare(strict_types=1);

namespace App\Modules\Platform\Notifications\Notifications;

use App\Modules\Platform\Identity\Models\Tenant;
use App\Modules\Platform\Notifications\Models\NotificationPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "You are approaching (or at) your limit."
 *
 * Delivered on several channels at once on purpose. The in-app record is the
 * durable one — mail can bounce, WhatsApp can be outside its window and the
 * socket can be closed — so it is the channel the dashboard reads back from,
 * and the others are best-effort acceleration.
 */
final class QuotaThresholdNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $metric,
        public readonly int $threshold,
        public readonly int $used,
        public readonly ?int $limit,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        // 'database' is not filterable: it is the record of what happened,
        // and letting a preference suppress it would mean the dashboard could
        // not show a limit the tenant is actually being blocked by.
        $channels = ['database'];

        foreach (['mail', 'whatsapp'] as $channel) {
            if ($this->wants($notifiable, $channel)) {
                $channels[] = $channel;
            }
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->atLimit()
            ? "You have reached your {$this->metricLabel()} limit"
            : "You have used {$this->threshold}% of your {$this->metricLabel()}";

        $message = (new MailMessage)
            ->subject($subject)
            ->greeting("Hello {$notifiable->name},")
            ->line($this->summaryLine());

        return $this->atLimit()
            ? $message
                ->line('New usage is being blocked until the next period or a plan change.')
                ->action('Review your plan', rtrim((string) config('kavo.frontend.dashboard'), '/').'/billing')
            : $message->action('View usage', rtrim((string) config('kavo.frontend.dashboard'), '/').'/');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'quota.threshold',
            'tenant_id' => $this->tenant->getKey(),
            'metric' => $this->metric,
            'threshold' => $this->threshold,
            'used' => $this->used,
            'limit' => $this->limit,
            'at_limit' => $this->atLimit(),
            'message' => $this->summaryLine(),
        ];
    }

    /** @return array<string, mixed> */
    public function toWhatsApp(object $notifiable): array
    {
        return [
            'tenant_id' => $this->tenant->getKey(),
            'to' => $notifiable->phone,
            'template' => 'quota_threshold',
            'event_type' => 'quota.threshold',
            'variables' => [
                'workspace' => $this->tenant->name,
                'percent' => (string) $this->threshold,
                'metric' => $this->metricLabel(),
            ],
        ];
    }

    /** Laravel's database channel writes this alongside the payload. */
    public function databaseType(object $notifiable): string
    {
        return 'quota.threshold';
    }

    private function atLimit(): bool
    {
        return $this->threshold >= 100;
    }

    private function metricLabel(): string
    {
        return str_replace('_', ' ', $this->metric);
    }

    private function summaryLine(): string
    {
        $of = $this->limit === null ? '' : " of {$this->limit}";

        return "{$this->tenant->name} has used {$this->used}{$of} {$this->metricLabel()} this period ({$this->threshold}%).";
    }

    /**
     * Opt-out, not opt-in: a tenant who has never touched preferences still
     * gets told they are about to be blocked.
     */
    private function wants(object $notifiable, string $channel): bool
    {
        if ($channel === 'whatsapp' && empty($notifiable->phone)) {
            return false;
        }

        $preference = NotificationPreference::query()
            ->where('tenant_id', $this->tenant->getKey())
            ->where('user_id', $notifiable->getKey())
            ->where('channel', $channel)
            ->where('event_type', 'quota.threshold')
            ->first();

        return $preference === null || (bool) $preference->enabled;
    }
}
