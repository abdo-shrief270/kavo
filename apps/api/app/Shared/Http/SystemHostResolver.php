<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Contracts\ResolvesHosts;

final class SystemHostResolver implements ResolvesHosts
{
    /** @return list<string> */
    public function resolve(string $host): array
    {
        // Both families: a host with a harmless A record and an AAAA record
        // pointing at a link-local address is the obvious way past a check
        // that only ever looks at IPv4.
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address) && $address !== '') {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }
}
