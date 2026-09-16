<?php

declare(strict_types=1);

namespace App\Modules\Platform\Identity\Models;

use App\Models\User;
use App\Modules\Platform\Domains\Models\Domain;
use App\Shared\Enums\Product;
use App\Shared\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The tenant itself is not tenant-scoped — it is the thing being scoped to,
 * so it deliberately does not use the BelongsToTenant trait.
 */
final class Tenant extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'status', 'product', 'plan_id', 'trial_ends_at', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'product' => Product::class,
            'trial_ends_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['role', 'invited_at', 'joined_at'])
            ->withTimestamps();
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function isOnTrial(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }

    public function canServeRequests(): bool
    {
        return $this->status->canServeRequests() || $this->isOnTrial();
    }
}
