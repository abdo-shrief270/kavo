<?php

declare(strict_types=1);

namespace App\Modules\Platform\Webhooks\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Inbound provider event. Not tenant-scoped: the tenant is only known after
 * the payload is parsed, which happens in the queued job.
 *
 * @property int $id
 * @property string $provider
 * @property string $external_event_id
 * @property ?string $event_type
 * @property array $payload
 * @property bool $signature_valid
 * @property Carbon $received_at
 * @property ?Carbon $processed_at
 * @property ?string $error
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
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
