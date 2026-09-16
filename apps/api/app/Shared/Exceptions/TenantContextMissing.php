<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use RuntimeException;

/**
 * Thrown when tenant-scoped work runs with no resolved tenant.
 *
 * This is deliberately fatal. Returning an empty result set instead would
 * hide the bug, and the same missing context on a write path would persist
 * rows with a null tenant_id.
 */
final class TenantContextMissing extends RuntimeException
{
    public static function make(string $context): self
    {
        return new self("No tenant resolved while {$context}. Tenant-scoped work must run inside a resolved tenant context.");
    }
}
