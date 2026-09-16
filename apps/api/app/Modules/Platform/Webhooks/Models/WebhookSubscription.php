<?php

declare(strict_types=1);

namespace App\Modules\Platform\Webhooks\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Hidden(['secret'])]
final class WebhookSubscription extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = ['tenant_id', 'url', 'secret', 'event_types', 'is_active', 'consecutive_failures', 'disabled_at'];

    protected function casts(): array
    {
        return ['event_types' => 'array', 'is_active' => 'boolean', 'disabled_at' => 'datetime'];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function subscribesTo(string $eventType): bool
    {
        return $this->is_active && in_array($eventType, $this->event_types ?? [], true);
    }
}
