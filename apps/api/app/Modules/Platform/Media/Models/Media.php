<?php

declare(strict_types=1);

namespace App\Modules\Platform\Media\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

final class Media extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'media';

    protected $fillable = ['tenant_id', 'disk', 'path', 'filename', 'mime', 'size_bytes', 'checksum', 'owner_type', 'owner_id', 'meta'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'size_bytes' => 'integer'];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Resolved through the disk rather than stored, so moving between R2,
     * Bunny or local storage is a config change and not a data migration.
     */
    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }
}
