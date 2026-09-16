<?php

declare(strict_types=1);

namespace App\Modules\Platform\Observability\Services;

/**
 * Holds the correlation id for the current request or job.
 *
 * Scoped, not singleton, for the same reason TenantContext is: under Octane
 * the container outlives the request, and a shared id would splice two
 * tenants' log lines into one trace.
 */
final class RequestContext
{
    private ?string $requestId = null;

    public function setRequestId(string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function forget(): void
    {
        $this->requestId = null;
    }
}
