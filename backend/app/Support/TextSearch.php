<?php

namespace App\Support;

/**
 * Text normalisation for the public search.
 *
 * Case and accents are ignored on both sides: the query is normalised in PHP, the database columns with
 * PostgreSQL's built-in translate() (no extension needed). Accents are written as \u{..} escapes so that
 * this file stays pure ASCII.
 */
final class TextSearch
{
    public const ACCENTS_FROM = "\u{E0}\u{E2}\u{E4}\u{E1}\u{E3}\u{E5}\u{E7}\u{E9}\u{E8}\u{EA}\u{EB}\u{ED}\u{EC}\u{EE}\u{EF}\u{F1}\u{F3}\u{F2}\u{F4}\u{F6}\u{F5}\u{FA}\u{F9}\u{FB}\u{FC}\u{FD}\u{FF}";

    public const ACCENTS_TO = 'aaaaaaceeeeiiiinooooouuuuyy';

    /** At most this many words of a query are used. */
    public const MAX_TERMS = 6;

    /** A word of at least STEM_FROM letters is searched by its first STEM_LENGTH letters (couturier -> coutu). */
    public const STEM_FROM = 6;

    public const STEM_LENGTH = 5;

    private const STOP_WORDS = [
        'a', 'au', 'aux', 'de', 'des', 'du', 'la', 'le', 'les', 'l', 'en', 'et', 'chez', 'pres', 'pour', 'dans', 'sur', 'un', 'une',
    ];

    /**
     * Lowercase, accents removed.
     */
    public static function normalize(string $text): string
    {
        return strtr(mb_strtolower($text), self::map());
    }

    /**
     * Searchable words of a query: normalised, punctuation and stop words removed, no duplicates.
     * Words only contain [a-z0-9], so they can never carry LIKE wildcards or SQL.
     *
     * @return list<string>
     */
    public static function terms(?string $query): array
    {
        if ($query === null) {
            return [];
        }

        $tokens = preg_split('/[^a-z0-9]+/', self::normalize($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $terms = [];

        foreach ($tokens as $token) {
            if (strlen($token) < 2 || in_array($token, self::STOP_WORDS, true)) {
                continue;
            }

            $terms[] = $token;
        }

        return array_slice(array_values(array_unique($terms)), 0, self::MAX_TERMS);
    }

    /**
     * What is actually looked for in the columns: the word itself, or its first letters for long words.
     */
    public static function pattern(string $term): string
    {
        return strlen($term) >= self::STEM_FROM ? substr($term, 0, self::STEM_LENGTH) : $term;
    }

    /**
     * SQL expression that lowercases a column and removes its accents. The column name comes from code,
     * never from a request.
     */
    public static function sqlNormalized(string $column): string
    {
        return 'translate(lower('.$column.'), \''.self::ACCENTS_FROM.'\', \''.self::ACCENTS_TO.'\')';
    }

    /**
     * @return array<string, string>
     */
    private static function map(): array
    {
        static $map = null;

        return $map ??= array_combine(mb_str_split(self::ACCENTS_FROM), mb_str_split(self::ACCENTS_TO));
    }
}
