<?php

declare(strict_types=1);

namespace App\Modules\Platform\Analytics\Models;

use App\Shared\Enums\Product;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Backed by a range-partitioned table. Writes go through AnalyticsIngestor,
 * which buffers in Redis and batch-inserts — this model is for reads and for
 * enrolling the table in the tenant isolation suite.
 */
final class AnalyticsEvent extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'user_id', 'product', 'event_name', 'properties', 'occurred_at', 'created_at'];

    protected function casts(): array
    {
        return [
            'product' => Product::class,
            'properties' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
