<?php

namespace App\Enums;

/**
 * Moderation status of a review. Stored as a string and protected by a CHECK constraint.
 */
enum ReviewStatus: string
{
    case Pending = 'pending';
    case Published = 'published';
    case Hidden = 'hidden';

    public function isPubliclyVisible(): bool
    {
        return $this === self::Published;
    }
}
