<?php

declare(strict_types=1);

namespace App\Modules\Platform\Webhooks\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Hidden(['secret'])]
/**
 * @property int $id
 * @property int $tenant_id
 * @property string $url
 * @property string $secret
 * @property array $event_types
 * @property bool $is_active
 * @property int $consecutive_failures
 * @property ?Carbon $disabled_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class WebhookSubscription extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = ['tenant_id', 'url', 'secret', 'event_types', 'is_active', 'consecutive_failures', 'disabled_at'];

    protected function casts(): array
    {
        return ['event_types' => 'array', 'is_active' => 'boolean', 'disabled_at' => 'datetime'];
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function subscribesTo(string $eventType): bool
    {
        return $this->is_active && in_array($eventType, $this->event_types ?? [], true);
    }
}
