<?php

declare(strict_types=1);

namespace App\Modules\Platform\Notifications\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Delivery log, keyed by provider message id so status callbacks reconcile. */
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
