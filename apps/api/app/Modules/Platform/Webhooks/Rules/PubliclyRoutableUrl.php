<?php

declare(strict_types=1);

namespace App\Modules\Platform\Webhooks\Rules;

use App\Shared\Exceptions\UnroutableEndpoint;
use App\Shared\Http\PublicEndpointGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects a destination at the point a tenant supplies it.
 *
 * This is the message-carrying half of the control, not the control itself:
 * the delivery job re-checks immediately before every send, because DNS for a
 * name that passed here can point somewhere else an hour later.
 */
final readonly class PubliclyRoutableUrl implements ValidationRule
{
    public function __construct(private PublicEndpointGuard $guard) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a URL.');

            return;
        }

        try {
            $this->guard->check($value);
        } catch (UnroutableEndpoint $e) {
            $fail('The :attribute is not a destination we can deliver to: '.$e->getMessage());
        }
    }
}
