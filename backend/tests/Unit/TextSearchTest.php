<?php

namespace Tests\Unit;

use App\Support\TextSearch;
use PHPUnit\Framework\TestCase;

class TextSearchTest extends TestCase
{
    public function test_normalize_lowercases_and_removes_accents(): void
    {
        $this->assertSame('agoe', TextSearch::normalize("Ago\u{E8}"));
        $this->assertSame('agoe', TextSearch::normalize("AGO\u{C8}"));
        $this->assertSame('electricite', TextSearch::normalize("\u{C9}LECTRICIT\u{C9}"));
        $this->assertSame('couture', TextSearch::normalize('COUTURE'));
        $this->assertSame('garcon', TextSearch::normalize("Gar\u{E7}on"));
    }

    public function test_every_accent_of_the_map_is_replaced_by_its_letter(): void
    {
        $from = mb_str_split(TextSearch::ACCENTS_FROM);
        $to = mb_str_split(TextSearch::ACCENTS_TO);

        $this->assertSame(count($from), count($to));

        foreach ($from as $index => $accent) {
            $this->assertSame($to[$index], TextSearch::normalize($accent), "Accent {$accent}");
            $this->assertSame($to[$index], TextSearch::normalize(mb_strtoupper($accent)), "Uppercase {$accent}");
        }
    }

    public function test_terms_drop_stop_words_punctuation_and_single_letters(): void
    {
        $this->assertSame(['couturier', 'tokoin'], TextSearch::terms("Couturier \u{E0} Tokoin"));
        $this->assertSame(['atelier'], TextSearch::terms("  l'atelier  "));
        $this->assertSame(['mecanicien', 'agoe'], TextSearch::terms("M\u{C9}CANICIEN, chez Ago\u{E8} !"));
        $this->assertSame(['24'], TextSearch::terms('a 24 h'));
    }

    public function test_terms_are_unique_and_limited(): void
    {
        $this->assertSame(['couture'], TextSearch::terms('couture COUTURE Couture'));
        $this->assertCount(TextSearch::MAX_TERMS, TextSearch::terms('un deux trois quatre cinq six sept huit neuf dix'));
    }

    public function test_an_empty_or_missing_query_has_no_terms(): void
    {
        $this->assertSame([], TextSearch::terms(null));
        $this->assertSame([], TextSearch::terms(''));
        $this->assertSame([], TextSearch::terms('   '));
        $this->assertSame([], TextSearch::terms('a de la'));
    }

    public function test_wildcards_and_sql_never_survive_the_tokenisation(): void
    {
        $terms = TextSearch::terms("100%_'; DROP TABLE artisans;--");

        $this->assertSame(['100', 'drop', 'table', 'artisans'], $terms);

        foreach ($terms as $term) {
            $this->assertMatchesRegularExpression('/^[a-z0-9]+$/', $term);
        }

        $this->assertSame([], TextSearch::terms('%'));
    }

    public function test_long_words_are_searched_by_their_first_letters(): void
    {
        $this->assertSame('coutu', TextSearch::pattern('couturier'));
        $this->assertSame('mecan', TextSearch::pattern('mecanicien'));
        $this->assertSame('tokoi', TextSearch::pattern('tokoin'));
        $this->assertSame('agoe', TextSearch::pattern('agoe'));
        $this->assertSame('macon', TextSearch::pattern('macon'));
    }

    public function test_the_sql_expression_normalises_the_given_column(): void
    {
        $sql = TextSearch::sqlNormalized('artisans.business_name');

        $this->assertStringStartsWith('translate(lower(artisans.business_name), ', $sql);
        $this->assertStringContainsString(TextSearch::ACCENTS_FROM, $sql);
        $this->assertStringContainsString(TextSearch::ACCENTS_TO, $sql);
    }
}
