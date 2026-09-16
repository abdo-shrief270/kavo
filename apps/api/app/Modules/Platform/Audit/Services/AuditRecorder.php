<?php

declare(strict_types=1);

namespace App\Modules\Platform\Audit\Services;

use App\Modules\Platform\Audit\Models\AuditLog;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class AuditRecorder
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Request $request,
    ) {}

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function record(string $action, ?Model $subject = null, ?array $old = null, ?array $new = null): void
    {
        AuditLog::withoutGlobalScopes()->create([
            'tenant_id' => $this->context->id(),
            'user_id' => $this->request->user()?->getKey(),
            'action' => $action,
            'auditable_type' => $subject === null ? null : $subject::class,
            'auditable_id' => $subject?->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'ip' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 255),
        ]);
    }

    /**
     * Cross-tenant reads by platform staff are the one legitimate way around
     * tenant isolation, so every one of them is recorded with no tenant_id —
     * which is what makes them findable later.
     */
    public function recordPlatformAccess(string $action, array $context = []): void
    {
        AuditLog::withoutGlobalScopes()->create([
            'tenant_id' => null,
            'user_id' => $this->request->user()?->getKey(),
            'action' => 'platform.'.$action,
            'new_values' => $context,
            'ip' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 255),
        ]);
    }
}
