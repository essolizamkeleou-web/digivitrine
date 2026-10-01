<?php

namespace App\Enums;

/**
 * Lifecycle of an artisan profile (business rule RG-04).
 *
 * Stored as a string and protected by a CHECK constraint in PostgreSQL.
 */
enum ArtisanStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Validated = 'validated';
    case Rejected = 'rejected';
    case Suspended = 'suspended';

    /**
     * Statuses a profile may move to from the current one.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Pending],
            self::Pending => [self::Validated, self::Rejected],
            // Validated -> Pending happens when a sensitive field is edited (RF-18).
            self::Validated => [self::Pending, self::Suspended],
            self::Rejected => [self::Pending],
            // Reinstatement is not defined in the specification: assumed, to be confirmed.
            self::Suspended => [self::Validated],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /**
     * Only validated profiles appear in public search (RG-05).
     */
    public function isPubliclyVisible(): bool
    {
        return $this === self::Validated;
    }
}
