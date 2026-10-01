<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\City;
use App\Models\Neighborhood;
use App\Models\Subcategory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GeographyAndCatalogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Asserts that the database rejects the given operation.
     *
     * The attempt runs in a nested transaction (a savepoint), so PostgreSQL
     * keeps working after the expected error and several attempts can be
     * chained in one test.
     */
    private function assertRejected(callable $attempt, string $label): void
    {
        try {
            DB::transaction($attempt);
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail("The database should have rejected: {$label}");
    }

    public function test_city_has_many_neighborhoods(): void
    {
        $city = City::factory()->create();
        Neighborhood::factory()->count(3)->for($city)->create();

        $this->assertCount(3, $city->neighborhoods);
        $this->assertTrue($city->neighborhoods->first()->city->is($city));
    }

    public function test_category_has_many_subcategories(): void
    {
        $category = Category::factory()->create();
        Subcategory::factory()->count(2)->for($category)->create();

        $this->assertCount(2, $category->subcategories);
        $this->assertTrue($category->subcategories->first()->category->is($category));
    }

    public function test_database_applies_default_country_code_and_active_flag(): void
    {
        DB::table('cities')->insert(['name' => 'Ville Test', 'slug' => 'ville-test']);

        $city = City::firstOrFail();

        $this->assertSame('TG', $city->country_code);
        $this->assertTrue($city->is_active);
    }

    public function test_city_slug_is_unique_per_country(): void
    {
        City::factory()->create(['slug' => 'centre', 'country_code' => 'TG']);
        City::factory()->create(['slug' => 'centre', 'country_code' => 'GH']);

        $this->assertSame(2, City::where('slug', 'centre')->count());

        $this->assertRejected(
            fn () => City::factory()->create(['slug' => 'centre', 'country_code' => 'TG']),
            'duplicate city slug in the same country'
        );
    }

    public function test_neighborhood_slug_is_unique_per_city(): void
    {
        $first = City::factory()->create();
        $second = City::factory()->create();

        Neighborhood::factory()->for($first)->create(['slug' => 'centre']);
        Neighborhood::factory()->for($second)->create(['slug' => 'centre']);

        $this->assertSame(2, Neighborhood::where('slug', 'centre')->count());

        $this->assertRejected(
            fn () => Neighborhood::factory()->for($first)->create(['slug' => 'centre']),
            'duplicate neighborhood slug in the same city'
        );
    }

    public function test_category_slug_is_unique(): void
    {
        Category::factory()->create(['slug' => 'couture']);

        $this->assertRejected(
            fn () => Category::factory()->create(['slug' => 'couture']),
            'duplicate category slug'
        );
    }

    public function test_subcategory_slug_is_unique_per_category(): void
    {
        $first = Category::factory()->create();
        $second = Category::factory()->create();

        Subcategory::factory()->for($first)->create(['slug' => 'femmes']);
        Subcategory::factory()->for($second)->create(['slug' => 'femmes']);

        $this->assertSame(2, Subcategory::where('slug', 'femmes')->count());

        $this->assertRejected(
            fn () => Subcategory::factory()->for($first)->create(['slug' => 'femmes']),
            'duplicate subcategory slug in the same category'
        );
    }

    public function test_slugs_must_be_lowercase_and_hyphenated(): void
    {
        $this->assertRejected(fn () => City::factory()->create(['slug' => 'Bad Slug']), 'city slug');
        $this->assertRejected(fn () => Neighborhood::factory()->create(['slug' => 'Bad Slug']), 'neighborhood slug');
        $this->assertRejected(fn () => Category::factory()->create(['slug' => 'Bad Slug']), 'category slug');
        $this->assertRejected(fn () => Subcategory::factory()->create(['slug' => 'Bad Slug']), 'subcategory slug');
        $this->assertRejected(fn () => City::factory()->create(['slug' => '-leading-hyphen']), 'leading hyphen');
    }

    public function test_country_code_must_be_two_uppercase_letters(): void
    {
        $this->assertRejected(fn () => City::factory()->create(['country_code' => 'tg']), 'lowercase country code');
        $this->assertRejected(fn () => City::factory()->create(['country_code' => 'TOG']), 'three-letter country code');
    }

    public function test_referenced_rows_cannot_be_deleted(): void
    {
        $city = City::factory()->create();
        Neighborhood::factory()->for($city)->create();

        $category = Category::factory()->create();
        Subcategory::factory()->for($category)->create();

        $this->assertRejected(fn () => $city->delete(), 'deleting a city that has neighborhoods');
        $this->assertRejected(fn () => $category->delete(), 'deleting a category that has subcategories');

        $this->assertTrue($city->exists());
        $this->assertDatabaseHas('cities', ['id' => $city->id]);
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_composite_unique_index_for_future_foreign_key_exists(): void
    {
        $count = DB::selectOne(
            "SELECT count(*) AS n FROM pg_indexes WHERE tablename = 'subcategories' AND indexname = 'subcategories_id_category_id_unique'"
        )->n;

        $this->assertSame(1, (int) $count);
    }
}
