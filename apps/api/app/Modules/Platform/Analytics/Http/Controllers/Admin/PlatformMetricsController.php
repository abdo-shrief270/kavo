<?php

declare(strict_types=1);

namespace App\Modules\Platform\Analytics\Http\Controllers\Admin;

use App\Modules\Platform\Audit\Services\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Cross-tenant platform metrics for the super-admin dashboard.
 *
 * This is the query that single-database tenancy buys: one statement answers
 * a question about every tenant at once, which schema-per-tenant or
 * database-per-tenant would turn into a fan-out.
 */
final class PlatformMetricsController
{
    public function __invoke(AuditRecorder $audit): JsonResponse
    {
        $audit->recordPlatformAccess('metrics.viewed');

        $db = DB::connection(config('kavo.tenancy.owner_connection'));

        return response()->json([
            'tenants' => [
                'total' => $db->table('tenants')->whereNull('deleted_at')->count(),
                'by_status' => $db->table('tenants')->whereNull('deleted_at')
                    ->select('status', DB::raw('count(*) as count'))
                    ->groupBy('status')->pluck('count', 'status'),
                'by_product' => $db->table('tenants')->whereNull('deleted_at')
                    ->select('product', DB::raw('count(*) as count'))
                    ->groupBy('product')->pluck('count', 'product'),
                'on_trial' => $db->table('tenants')->where('trial_ends_at', '>', now())->count(),
                'new_this_week' => $db->table('tenants')->where('created_at', '>=', now()->subWeek())->count(),
            ],
            'subscriptions' => $db->table('subscriptions')
                ->select('status', DB::raw('count(*) as count'))
                ->groupBy('status')->pluck('count', 'status'),
            'deliverability' => [
                'notifications_24h' => $db->table('notification_deliveries')
                    ->where('created_at', '>=', now()->subDay())
                    ->select('status', DB::raw('count(*) as count'))
                    ->groupBy('status')->pluck('count', 'status'),
                'webhooks_failing' => $db->table('webhook_deliveries')
                    ->whereIn('status', ['failed', 'dead_lettered'])
                    ->where('created_at', '>=', now()->subDay())->count(),
            ],
            'events_24h' => $db->table('analytics_events')
                ->where('occurred_at', '>=', now()->subDay())
                ->count(),
        ]);
    }
}
