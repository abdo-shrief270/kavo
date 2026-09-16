<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class IdempotencyKey extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'key', 'method', 'path', 'request_hash',
        'response_status', 'response_body', 'locked_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'locked_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function hasResponse(): bool
    {
        return $this->response_status !== null;
    }

    /**
     * A request that started but never finished — the process died between
     * claiming the key and recording a response. Held briefly, then allowed
     * to retry rather than blocking the key forever.
     */
    public function isStale(): bool
    {
        return ! $this->hasResponse()
            && $this->locked_at !== null
            && $this->locked_at->lt(now()->subMinute());
    }
}
