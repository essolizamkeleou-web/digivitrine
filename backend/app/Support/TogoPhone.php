<?php

namespace App\Support;

/**
 * Togolese phone numbers (business rule RG-01).
 *
 * Accepted: "90 12 34 56", "90123456", "+228 90123456", "00228 90123456"
 * (spaces, dots, dashes and parentheses are ignored). The canonical form is "+228" followed by 8 digits.
 * Numbers from other countries are not accepted yet: artisan phone numbers are Togolese only.
 */
final class TogoPhone
{
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $compact = preg_replace('/[\s.\-()]/', '', $value);

        if ($compact === null || preg_match('/^(?:\+228|00228)?([0-9]{8})$/', $compact, $matches) !== 1) {
            return null;
        }

        return '+228'.$matches[1];
    }
}
