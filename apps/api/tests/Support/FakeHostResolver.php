<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Shared\Contracts\ResolvesHosts;

/**
 * DNS the tests control.
 *
 * `.test` names resolve nowhere, and the interesting answers — a name that
 * returns 169.254.169.254, or one public address alongside a private one —
 * are ones no registrar would let anybody set up anyway.
 */
final class FakeHostResolver implements ResolvesHosts
{
    /** @var array<string, list<string>> */
    private array $answers = [];

    /** @param list<string> $default what an unlisted host resolves to */
    public function __construct(private array $default = ['93.184.216.34']) {}

    public function answer(string $host, string ...$addresses): self
    {
        $this->answers[$host] = array_values($addresses);

        return $this;
    }

    /** @return list<string> */
    public function resolve(string $host): array
    {
        return $this->answers[$host] ?? $this->default;
    }
}
