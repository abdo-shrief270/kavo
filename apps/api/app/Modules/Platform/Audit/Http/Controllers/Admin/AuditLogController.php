<?php

declare(strict_types=1);

namespace App\Modules\Platform\Audit\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reads the audit trail, including platform-scope access records.
 *
 * Deliberately read-only: an audit log that an administrator can edit is not
 * an audit log.
 */
final class AuditLogController
{
    public function index(Request $request): JsonResponse
    {
        $logs = DB::connection(config('kavo.tenancy.owner_connection'))
            ->table('audit_logs')
            ->when($request->integer('tenant_id'), fn ($q, int $id) => $q->where('tenant_id', $id))
            ->when($request->string('action')->toString(), fn ($q, string $a) => $q->where('action', 'like', $a.'%'))
            ->when($request->integer('user_id'), fn ($q, int $id) => $q->where('user_id', $id))
            ->orderByDesc('id')
            ->paginate(50);

        return response()->json($logs);
    }
}
