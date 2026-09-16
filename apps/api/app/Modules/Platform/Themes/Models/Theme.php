<?php

declare(strict_types=1);

namespace App\Modules\Platform\Themes\Models;

use App\Shared\Enums\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Theme definitions are platform catalogue; per-tenant overrides live elsewhere. */
final class Theme extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'product', 'version', 'manifest', 'is_active'];

    protected function casts(): array
    {
        return ['product' => Product::class, 'manifest' => 'array', 'is_active' => 'boolean'];
    }

    /**
     * Sections a storefront may render, declared by the theme manifest. The
     * rendering contract is the platform's concern; the sections themselves
     * are a vertical concern.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sections(): array
    {
        return $this->manifest['sections'] ?? [];
    }
}
