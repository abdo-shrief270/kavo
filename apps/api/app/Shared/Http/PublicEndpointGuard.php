<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Contracts\ResolvesHosts;
use App\Shared\Exceptions\UnroutableEndpoint;

/**
 * Decides whether the platform is willing to make a request to a URL a tenant
 * supplied.
 *
 * A tenant-controlled URL that the server fetches is server-side request
 * forgery waiting to happen: the request leaves from inside our network, with
 * our source address, and anything that comes back can be read by whoever
 * supplied the URL. Cloud metadata at 169.254.169.254 is the classic target
 * and hands out credentials to anyone who asks from the right place.
 *
 * Checking the scheme is not enough, because it only describes the string. The
 * address behind the name is what the request actually reaches, so this
 * resolves the host and checks every answer — and the caller pins the
 * connection to the address that was checked.
 */
final readonly class PublicEndpointGuard
{
    /**
     * Ranges the IP filter's own flags miss, in CIDR form.
     *
     * FILTER_FLAG_NO_PRIV_RANGE and FILTER_FLAG_NO_RES_RANGE between them
     * cover RFC 1918, loopback, link-local (including 169.254.169.254),
     * IPv4-mapped IPv6 and unique-local v6. These are the gaps.
     */
    private const BLOCKED_RANGES = [
        '100.64.0.0/10',   // carrier-grade NAT — routable inside an ISP, not on the internet
        '192.0.0.0/24',    // IETF protocol assignments
        '192.0.2.0/24',    // TEST-NET-1
        '198.18.0.0/15',   // benchmarking
        '198.51.100.0/24', // TEST-NET-2
        '203.0.113.0/24',  // TEST-NET-3
        '224.0.0.0/4',     // multicast
        '64:ff9b::/96',    // NAT64, which translates straight back into IPv4
        '2002::/16',       // 6to4
    ];

    public function __construct(private ResolvesHosts $resolver) {}

    /**
     * @throws UnroutableEndpoint
     */
    public function check(string $url): PublicEndpoint
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnroutableEndpoint('The URL could not be parsed.');
        }

        if (strtolower($parts['scheme']) !== 'https') {
            throw new UnroutableEndpoint('Only https destinations are allowed.');
        }

        // user:pass@host is a phishing affordance and a parser-confusion one:
        // some clients read the authority differently to others.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnroutableEndpoint('The URL may not carry credentials.');
        }

        $host = trim($parts['host'], '[]');
        $port = $parts['port'] ?? 443;

        if ($host === '') {
            throw new UnroutableEndpoint('The URL has no host.');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : $this->resolver->resolve($host);

        if ($addresses === []) {
            // Fail closed. A name that does not resolve now is not a name we
            // will send a signed payload to on the strength of hoping.
            throw new UnroutableEndpoint('The host does not resolve.');
        }

        foreach ($addresses as $address) {
            if (! $this->isPubliclyRoutable($address)) {
                // Every answer has to pass, not just one: a name that returns
                // a public address and a private one is the cheapest way past
                // a check that stops at the first success.
                throw new UnroutableEndpoint('The host resolves to an address that is not publicly routable.');
            }
        }

        return new PublicEndpoint($url, $host, (int) $port, $addresses[0]);
    }

    public function allows(string $url): bool
    {
        try {
            $this->check($url);

            return true;
        } catch (UnroutableEndpoint) {
            return false;
        }
    }

    private function isPubliclyRoutable(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        foreach (self::BLOCKED_RANGES as $range) {
            if ($this->inRange($address, $range)) {
                return false;
            }
        }

        return true;
    }

    private function inRange(string $address, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        $addressBytes = inet_pton($address);
        $subnetBytes = inet_pton($subnet);

        if ($addressBytes === false || $subnetBytes === false) {
            return false;
        }

        // Different families never match, and comparing them byte-wise would
        // compare a v6 prefix against v4 octets.
        if (strlen($addressBytes) !== strlen($subnetBytes)) {
            return false;
        }

        $bits = (int) $bits;
        $wholeBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;

        if ($wholeBytes > 0 && strncmp($addressBytes, $subnetBytes, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;

        return (ord($addressBytes[$wholeBytes]) & $mask) === (ord($subnetBytes[$wholeBytes]) & $mask);
    }
}
