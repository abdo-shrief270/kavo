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

    /**
     * Bind a tenant for the duration of a callback, then restore whatever was
     * bound before.
     *
     * Save-and-restore rather than bind-then-clear, because callers cannot
     * assume they are the outermost one. A queued listener runs on a worker
     * with nothing bound, but the same listener running inline — a sync queue,
     * or a synchronous dispatch — sits inside a request that already has a
     * tenant bound, and clearing it there strands the rest of that request
     * with no tenant. The symptom is not a clean failure: Postgres aborts the
     * surrounding transaction and every later statement fails.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runBound(int $tenantId, callable $callback): mixed
    {
        $previous = $this->currentValue();

        $this->bind($tenantId);

        try {
            return $callback();
        } finally {
            $this->apply($previous ?? '');
        }
    }

    /** The GUC as Postgres currently has it, or null outside Postgres. */
    private function currentValue(): ?string
    {
        $connection = $this->db->connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return null;
        }

        $value = $connection->scalar('SELECT current_setting(?, true)', [config('kavo.tenancy.guc')]);

        return $value === null ? '' : (string) $value;
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
