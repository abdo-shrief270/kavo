<?php

declare(strict_types=1);

namespace App\Shared\Http;

/**
 * A destination that has been checked and resolved, carrying the address it
 * was checked at.
 *
 * The address travels with the URL on purpose. Validating a hostname and then
 * letting the HTTP client look it up again leaves a window in which DNS can
 * answer differently the second time, which is the whole trick behind DNS
 * rebinding. Pinning the connection to the address we validated closes it.
 */
final readonly class PublicEndpoint
{
    public function __construct(
        public string $url,
        public string $host,
        public int $port,
        public string $address,
    ) {}

    /**
     * curl's --resolve entry: send this host:port to this address, while TLS
     * still verifies the certificate against the hostname.
     */
    public function curlResolveEntry(): string
    {
        return $this->host.':'.$this->port.':'.$this->address;
    }

    /**
     * Request options that pin the connection.
     *
     * Best effort by design: without ext-curl the option is simply ignored and
     * the pre-flight check remains the control. It is never the only one.
     *
     * @return array<string, mixed>
     */
    public function pinnedRequestOptions(): array
    {
        if (! defined('CURLOPT_RESOLVE')) {
            return [];
        }

        return ['curl' => [CURLOPT_RESOLVE => [$this->curlResolveEntry()]]];
    }
}
