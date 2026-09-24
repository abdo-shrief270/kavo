<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Catalogue\Enums;

enum ProductStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';

    /** Only one status is ever visible to a shopper. */
    public function isPublic(): bool
    {
        return $this === self::Active;
    }

    /**
     * An archived product still occupies a slot in the tenant's plan.
     *
     * Archiving is how a merchant retires a line without losing its order
     * history, so it is not a way to get allowance back — only deleting is.
     */
    public function stillCountsTowardQuota(): bool
    {
        return true;
    }
}
