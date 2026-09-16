<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PlanFeature extends Model
{
    use HasFactory;

    protected $fillable = ['plan_id', 'feature_key', 'limit_value', 'overage_behavior'];

    protected function casts(): array
    {
        return ['limit_value' => 'integer'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isUnlimited(): bool
    {
        return $this->limit_value === null;
    }
}
