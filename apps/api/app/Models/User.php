<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Platform\Identity\Models\Tenant;
use App\Modules\Platform\Identity\Models\TenantMembership;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Identity is global: one user can belong to many tenants. Authorisation is
 * always per-tenant, resolved from the tenant_user pivot.
 */
#[Fillable(['name', 'email', 'password', 'phone', 'is_platform_admin', 'active_tenant_id'])]
#[Hidden(['password', 'remember_token'])]
/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property ?Carbon $email_verified_at
 * @property string $password
 * @property ?string $remember_token
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property bool $is_platform_admin
 * @property ?int $active_tenant_id
 * @property ?string $phone
 * @property ?Carbon $last_seen_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
        ];
    }

    /** @return BelongsToMany<Tenant, $this, TenantMembership> */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class)
            ->using(TenantMembership::class)
            ->withPivot(['role', 'invited_at', 'joined_at'])
            ->withTimestamps();
    }

    public function belongsToTenant(Tenant $tenant): bool
    {
        return $this->tenants()->whereKey($tenant->getKey())->exists();
    }

    /**
     * The user's role inside one tenant. Roles never span tenants, so this
     * is the only meaningful way to ask what someone may do.
     */
    public function roleIn(Tenant $tenant): ?string
    {
        $membership = $this->tenants()->whereKey($tenant->getKey())->first();

        return $membership?->pivot->role;
    }
}
