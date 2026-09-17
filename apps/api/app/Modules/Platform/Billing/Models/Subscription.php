<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $plan_id
 * @property string $status
 * @property ?Carbon $trial_ends_at
 * @property ?Carbon $current_period_start
 * @property ?Carbon $current_period_end
 * @property ?Carbon $canceled_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class Subscription extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = ['tenant_id', 'plan_id', 'status', 'trial_ends_at', 'current_period_start', 'current_period_end', 'canceled_at'];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'trialing'], true)
            && ($this->canceled_at === null || $this->canceled_at->isFuture());
    }
}
