<?php

declare(strict_types=1);

namespace App\Modules\Platform\Audit\Services;

use App\Modules\Platform\Audit\Models\AuditLog;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $tenantId = $this->context->id();

        // An action with no tenant is a platform action by definition, and
        // takes the platform path below — a tenant-scoped insert with a null
        // tenant_id is rejected by RLS, correctly.
        if ($tenantId === null) {
            $this->writeAsPlatform($action, [
                'auditable_type' => $subject === null ? null : $subject::class,
                'auditable_id' => $subject?->getKey(),
                'old' => $old,
                'new' => $new,
            ]);

            return;
        }

        AuditLog::create([
            'tenant_id' => $tenantId,
            'user_id' => $this->request->user()?->getKey(),
            'action' => $action,
            'auditable_type' => $subject === null ? null : $subject::class,
            'auditable_id' => $subject?->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'ip' => $this->request->ip(),
            'user_agent' => $this->userAgent(),
        ]);
    }

    /**
     * Cross-tenant reads by platform staff are the one legitimate way around
     * tenant isolation, so every one is recorded.
     *
     * @param  array<string, mixed>  $context
     */
    public function recordPlatformAccess(string $action, array $context = []): void
    {
        $this->writeAsPlatform('platform.'.$action, $context);
    }

    /**
     * Platform entries carry no tenant_id. The audit_logs policy permits
     * writing such a row and still refuses to read one back, so this stays on
     * the application connection — inside the caller's transaction, where an
     * audit write belongs.
     *
     * @param  array<string, mixed>  $context
     */
    private function writeAsPlatform(string $action, array $context): void
    {
        DB::table('audit_logs')
            ->insert([
                'tenant_id' => null,
                'user_id' => $this->request->user()?->getKey(),
                'action' => $action,
                'new_values' => json_encode($context, JSON_THROW_ON_ERROR),
                'ip' => $this->request->ip(),
                'user_agent' => $this->userAgent(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function userAgent(): ?string
    {
        $agent = $this->request->userAgent();

        return $agent === null ? null : mb_substr($agent, 0, 255);
    }
}
