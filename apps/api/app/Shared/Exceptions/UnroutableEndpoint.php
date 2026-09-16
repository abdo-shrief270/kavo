<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use RuntimeException;

/**
 * A destination the platform refuses to send to.
 *
 * Thrown rather than returned so a caller cannot forget to check: the failure
 * mode of a missed check here is the platform fetching an internal URL on a
 * stranger's behalf and handing back what it found.
 */
final class UnroutableEndpoint extends RuntimeException {}
