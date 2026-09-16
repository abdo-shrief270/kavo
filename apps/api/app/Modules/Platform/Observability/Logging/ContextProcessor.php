<?php

declare(strict_types=1);

namespace App\Modules\Platform\Observability\Logging;

use App\Modules\Platform\Observability\Services\RequestContext;
use App\Shared\Tenancy\TenantContext;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Stamps every log line with who and where.
 *
 * In a shared-schema multi-tenant system, a log line without a tenant is
 * close to useless: "checkout failed" is a support ticket, "checkout failed
 * for tenant 41 on POST /api/orders in release abc123" is a bug report. This
 * runs on every channel so the context cannot be forgotten at a call site.
 */
final class ContextProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra + array_filter([
            'request_id' => $this->requestId(),
            'tenant_id' => $this->tenantId(),
            'product' => $this->product(),
            'release' => config('app.release'),
            'environment' => config('app.env'),
            'module' => $this->moduleFor($record->channel),
        ], static fn (mixed $value): bool => $value !== null);

        return $record->with(extra: $extra);
    }

    private function requestId(): ?string
    {
        return app()->bound(RequestContext::class)
            ? app(RequestContext::class)->requestId()
            : null;
    }

    private function tenantId(): ?int
    {
        // Resolving the container during logging must never throw — a broken
        // logger would hide the very error it was asked to record.
        try {
            return app(TenantContext::class)->id();
        } catch (\Throwable) {
            return null;
        }
    }

    private function product(): ?string
    {
        try {
            return app(TenantContext::class)->get()?->product?->value;
        } catch (\Throwable) {
            return null;
        }
    }

    private function moduleFor(string $channel): string
    {
        return $channel === 'local' || $channel === 'production' ? 'app' : $channel;
    }
}
