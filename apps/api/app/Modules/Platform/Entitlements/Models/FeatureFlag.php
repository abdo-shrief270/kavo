<?php

declare(strict_types=1);

namespace App\Modules\Platform\Entitlements\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** Platform-level configuration; scope_value narrows it to a tenant or product. */
/**
 * @property int $id
 * @property string $key
 * @property string $scope
 * @property ?string $scope_value
 * @property bool $enabled
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class FeatureFlag extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'scope', 'scope_value', 'enabled', 'rollout_percentage'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'rollout_percentage' => 'integer'];
    }
}
