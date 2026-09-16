<?php

declare(strict_types=1);

namespace App\Modules\Platform\Identity\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasJoined(): bool
    {
        return $this->joined_at !== null;
    }
}
