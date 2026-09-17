<?php

declare(strict_types=1);

namespace App\Modules\Platform\Notifications\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** Delivery log, keyed by provider message id so status callbacks reconcile. */
/**
 * @property int $id
 * @property int $tenant_id
 * @property string $channel
 * @property string $event_type
 * @property string $recipient
 * @property ?string $template_code
 * @property string $status
 * @property ?string $provider_message_id
 * @property array $payload
 * @property ?string $error
 * @property int $attempts
 * @property ?Carbon $sent_at
 * @property ?Carbon $delivered_at
 * @property ?Carbon $failed_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class NotificationDelivery extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = ['tenant_id', 'channel', 'event_type', 'recipient', 'template_code', 'status', 'provider_message_id', 'payload', 'error', 'attempts', 'sent_at', 'delivered_at', 'failed_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'sent_at' => 'datetime', 'delivered_at' => 'datetime', 'failed_at' => 'datetime'];
    }
}
