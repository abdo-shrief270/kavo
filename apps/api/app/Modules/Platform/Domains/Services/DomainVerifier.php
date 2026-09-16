<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domains\Services;

use App\Modules\Platform\Domains\Models\Domain;

/**
 * Proves the tenant controls the hostname before we will serve it.
 *
 * Verification gates TLS issuance: the Caddy on-demand ask endpoint only
 * approves hostnames that reached `verifying` or `active`, so an unverified
 * hostname can never trigger a certificate request in our name.
 */
final class DomainVerifier
{
    public function verify(Domain $domain): bool
    {
        $domain->update(['status' => 'verifying']);

        $expected = $domain->expectedDnsRecord();
        $records = $this->lookupTxt('_kavo-challenge.'.$domain->hostname);

        foreach ($records as $record) {
            if (hash_equals($expected, trim($record))) {
                $domain->update([
                    'status' => 'active',
                    'verified_at' => now(),
                    'last_error' => null,
                ]);

                return true;
            }
        }

        $domain->update([
            'status' => 'failed',
            'last_error' => 'Expected TXT record was not found. DNS changes can take time to propagate.',
        ]);

        return false;
    }

    /** @return array<int, string> */
    private function lookupTxt(string $hostname): array
    {
        $records = @dns_get_record($hostname, DNS_TXT);

        if ($records === false) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (array $record): string => (string) ($record['txt'] ?? ''),
            $records
        )));
    }
}
