<?php

declare(strict_types=1);

namespace App\Modules\Platform\Identity\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property string $role
 * @property ?Carbon $invited_at
 * @property ?Carbon $joined_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class TenantMembership extends Model
{
    protected $table = 'tenant_user';

    protected $fillable = ['tenant_id', 'user_id', 'role', 'invited_at', 'joined_at'];

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'joined_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasJoined(): bool
    {
        return $this->joined_at !== null;
    }
}
