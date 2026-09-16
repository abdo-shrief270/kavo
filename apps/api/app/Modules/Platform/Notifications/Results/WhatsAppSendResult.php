<?php

declare(strict_types=1);

namespace App\Modules\Platform\Notifications\Results;

final readonly class WhatsAppSendResult
{
    private function __construct(
        public bool $accepted,
        public ?string $messageId,
        public ?string $error,
        public bool $retryable,
    ) {}

    public static function accepted(string $messageId): self
    {
        return new self(true, $messageId, null, false);
    }

    /**
     * A permanent failure — an unapproved template, an invalid number, a
     * closed conversation window. Retrying cannot help, so the job should
     * not.
     */
    public static function rejected(string $error): self
    {
        return new self(false, null, $error, false);
    }

    /** A transient failure — timeout, 5xx, rate limit. Worth retrying. */
    public static function failed(string $error): self
    {
        return new self(false, null, $error, true);
    }
}
