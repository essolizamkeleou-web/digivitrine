<?php

namespace App\Enums;

/**
 * Verification state of a neighborhood. "Verified" needs two documents in hand (see the geography README).
 */
enum GeoVerification: string
{
    case ToVerify = 'a_verifier';
    case Verified = 'verifie';
}
