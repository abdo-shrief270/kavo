<?php

declare(strict_types=1);

namespace App\Modules\Platform\Webhooks\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Surfaced in the merchant dashboard with manual retry. Silently dropping a
 * delivery destroys trust in an integration platform, so failures stay
 * visible rather than disappearing into a log.
 */
final class WebhookDelivery extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = ['tenant_id', 'webhook_subscription_id', 'event_type', 'payload', 'attempt', 'status', 'status_code', 'response_body', 'delivered_at', 'failed_at', 'next_attempt_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'next_attempt_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'webhook_subscription_id');
    }

    public function isRetryable(): bool
    {
        return in_array($this->status, ['pending', 'failed'], true);
    }
}
