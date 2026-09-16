<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * DNS, behind an interface.
 *
 * Not an abstraction for its own sake: the destination guard has to be tested
 * against addresses that do not exist in public DNS — a host that answers with
 * 169.254.169.254, one that answers with a mix of public and private records,
 * one that does not resolve at all. Faking the client instead of the resolver
 * would leave the actual rules unexercised.
 */
interface ResolvesHosts
{
    /**
     * Every address a host answers with, IPv4 and IPv6.
     *
     * @return list<string> empty when the host does not resolve
     */
    public function resolve(string $host): array;
}
