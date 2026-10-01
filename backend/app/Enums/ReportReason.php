<?php

namespace App\Enums;

/**
 * Why a profile is reported. Stored as a string and protected by a CHECK constraint.
 */
enum ReportReason: string
{
    case FalseInformation = 'false_information';
    case InappropriateContent = 'inappropriate_content';
    case DuplicateProfile = 'duplicate_profile';
    case WrongContact = 'wrong_contact';
    case Other = 'other';
}
