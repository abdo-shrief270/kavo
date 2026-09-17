<?php

declare(strict_types=1);

namespace App\Modules\Platform\Entitlements\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $metric_key
 * @property Carbon $period_start
 * @property int $value
 * @property ?Carbon $flushed_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class UsageCounter extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = ['tenant_id', 'metric_key', 'period_start', 'value', 'flushed_at'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'flushed_at' => 'datetime', 'value' => 'integer'];
    }
}
