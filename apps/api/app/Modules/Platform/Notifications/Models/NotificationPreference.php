<?php

declare(strict_types=1);

namespace App\Modules\Platform\Notifications\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property ?int $user_id
 * @property string $channel
 * @property string $event_type
 * @property bool $enabled
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class NotificationPreference extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = ['tenant_id', 'user_id', 'channel', 'event_type', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
