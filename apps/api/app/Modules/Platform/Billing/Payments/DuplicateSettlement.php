<?php

declare(strict_types=1);

namespace App\Modules\Platform\Billing\Payments;

use RuntimeException;

/**
 * A provider callback we have already applied.
 *
 * Not an error condition: providers retry by design. The webhook handler
 * catches it and acknowledges, which is what stops the retry loop without
 * processing the event twice.
 */
final class DuplicateSettlement extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This settlement event has already been applied.');
    }
}
