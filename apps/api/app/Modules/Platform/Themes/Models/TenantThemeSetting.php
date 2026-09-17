<?php

declare(strict_types=1);

namespace App\Modules\Platform\Themes\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $theme_code
 * @property array $settings
 * @property array $design_tokens
 * @property bool $is_published
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class TenantThemeSetting extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = ['tenant_id', 'theme_code', 'settings', 'design_tokens', 'is_published'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'design_tokens' => 'array', 'is_published' => 'boolean'];
    }
}
