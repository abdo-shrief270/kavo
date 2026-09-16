<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domains\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class Domain extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = ['tenant_id', 'hostname', 'status', 'verification_token', 'verified_at', 'ssl_issued_at', 'ssl_expires_at', 'last_error'];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'ssl_issued_at' => 'datetime',
            'ssl_expires_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** The DNS TXT record the tenant adds to prove control of the hostname. */
    public function expectedDnsRecord(): string
    {
        return 'kavo-verification='.$this->verification_token;
    }
}
