<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;

/**
 * Binds the resolved tenant to the Postgres session so RLS policies can see it.
 *
 * Policies read current_setting('app.tenant_id'). The value is set per request
 * and MUST be cleared when the request ends: under Octane and with persistent
 * connections the same connection serves the next tenant, and a stale GUC
 * there is a cross-tenant read.
 */
final readonly class TenantDatabaseSession
{
    public function __construct(private DatabaseManager $db) {}

    public function bind(int $tenantId): void
    {
        $this->apply((string) $tenantId);
    }

    /**
     * Clear the GUC. Policies compare against NULL afterwards, which matches
     * no rows — so a leaked connection fails closed rather than open.
     */
    public function clear(): void
    {
        $this->apply('');
    }

    private function apply(string $value): void
    {
        $connection = $this->db->connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $this->setConfig($connection, $value);
    }

    private function setConfig(ConnectionInterface $connection, string $value): void
    {
        $guc = config('kavo.tenancy.guc');

        // set_config(..., false) is session-scoped rather than transaction-
        // scoped, so it survives outside an explicit transaction. That is why
        // clearing it on request termination is mandatory, not optional.
        $connection->statement('SELECT set_config(?, ?, false)', [$guc, $value]);
    }
}
