<?php

declare(strict_types=1);

namespace App\Modules\Platform\Entitlements\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Platform-level configuration; scope_value narrows it to a tenant or product. */
final class FeatureFlag extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'scope', 'scope_value', 'enabled', 'rollout_percentage'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'rollout_percentage' => 'integer'];
    }
}
