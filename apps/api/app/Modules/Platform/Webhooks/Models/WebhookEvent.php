<?php

declare(strict_types=1);

namespace App\Modules\Platform\Webhooks\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Inbound provider event. Not tenant-scoped: the tenant is only known after
 * the payload is parsed, which happens in the queued job.
 */
final class WebhookEvent extends Model
{
    use HasFactory;

    protected $fillable = ['provider', 'external_event_id', 'event_type', 'payload', 'signature_valid', 'received_at', 'processed_at', 'error'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_valid' => 'boolean',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * The job is idempotent, not the endpoint. Providers retry aggressively,
     * and double-processing an inbound order message means a duplicate order.
     */
    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }
}
