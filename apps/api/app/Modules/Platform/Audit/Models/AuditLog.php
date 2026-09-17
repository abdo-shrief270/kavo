<?php

declare(strict_types=1);

namespace App\Modules\Platform\Audit\Models;

use App\Models\User;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property ?int $tenant_id
 * @property ?int $user_id
 * @property string $action
 * @property ?array $old_values
 * @property ?array $new_values
 * @property ?string $ip
 * @property ?string $user_agent
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class AuditLog extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = ['tenant_id', 'user_id', 'action', 'auditable_type', 'auditable_id', 'old_values', 'new_values', 'ip', 'user_agent'];

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array'];
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
