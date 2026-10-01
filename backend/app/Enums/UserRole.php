<?php

namespace App\Enums;

/**
 * Account role. One role per user (see ADR-12).
 *
 * Stored as a string and protected by a CHECK constraint in PostgreSQL.
 */
enum UserRole: string
{
    case Artisan = 'artisan';
    case FieldAgent = 'field_agent';
    case Moderator = 'moderator';
    case Admin = 'admin';
}
