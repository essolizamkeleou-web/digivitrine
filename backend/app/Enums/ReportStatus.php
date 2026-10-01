<?php

namespace App\Enums;

/**
 * Moderation status of a report. Stored as a string and protected by a CHECK constraint.
 */
enum ReportStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';

    public function isClosed(): bool
    {
        return $this !== self::Open;
    }
}
