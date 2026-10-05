<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\City;
use App\Models\Neighborhood;
use App\Models\Subcategory;
use App\Support\ApiMessages;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CatalogueApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<string>
     */
    private function slugs(TestResponse $response, string $path = 'data'): array
    {
        return collect($response->json($path))->pluck('slug')->all();
    }

    public function test_categories_list_only_active_rows_in_position_order_with_their_active_subcategories(): void
    {
        $couture = Category::factory()->create(['slug' => 'couture', 'name' => 'Couture', 'position' => 10]);
        Category::factory()->create(['slug' => 'menuiserie', 'name' => 'Menuiserie', 'position' => 20]);
        Category::factory()->create(['slug' => 'archive', 'is_active' => false, 'position' => 5]);
        Subcategory::factory()->for($couture)->create(['slug' => 'femmes', 'position' => 20]);
        Subcategory::factory()->for($couture)->create(['slug' => 'hommes', 'position' => 10]);
        Subcategory::factory()->for($couture)->create(['slug' => 'ancien', 'is_active' => false]);

        $response = $this->get('/api/v1/categories')->assertOk();

        $this->assertSame(['couture', 'menuiserie'], $this->slugs($response));
        $this->assertSame(['hommes', 'femmes'], $this->slugs($response, 'data.0.subcategories'));
        $this->assertSame([], $response->json('data.1.subcategories'));
    }

    public function test_responses_expose_only_public_fields(): void
    {
        $category = Category::factory()->create(['slug' => 'couture']);
        Subcategory::factory()->for($category)->create(['slug' => 'femmes']);
        $city = City::factory()->create(['slug' => 'lome']);
        Neighborhood::factory()->for($city)->create(['slug' => 'alpha']);

        $categoryJson = $this->get('/api/v1/categories')->json('data.0');
        $this->assertEqualsCanonicalizing(['slug', 'name', 'subcategories'], array_keys($categoryJson));
        $this->assertEqualsCanonicalizing(['slug', 'name'], array_keys($categoryJson['subcategories'][0]));

        $cityJson = $this->get('/api/v1/cities')->json('data.0');
        $this->assertEqualsCanonicalizing(['slug', 'name', 'country_code'], array_keys($cityJson));

        $neighborhoodJson = $this->get('/api/v1/neighborhoods')->json('data.0');
        $this->assertEqualsCanonicalizing(['slug', 'name', 'city'], array_keys($neighborhoodJson));
        $this->assertEqualsCanonicalizing(['slug', 'name', 'country_code'], array_keys($neighborhoodJson['city']));
    }

    public function test_a_category_can_be_read_by_slug_with_its_subcategories(): void
    {
        $category = Category::factory()->create(['slug' => 'couture', 'name' => 'Couture']);
        Subcategory::factory()->for($category)->create(['slug' => 'hommes', 'position' => 10]);
        Subcategory::factory()->for($category)->create(['slug' => 'ancien', 'is_active' => false]);

        $this->get('/api/v1/categories/couture')
            ->assertOk()
            ->assertJsonPath('data.slug', 'couture')
            ->assertJsonPath('data.name', 'Couture')
            ->assertJsonPath('data.subcategories.0.slug', 'hommes')
            ->assertJsonCount(1, 'data.subcategories');
    }

    public function test_an_unknown_inactive_or_malformed_category_is_a_clean_404(): void
    {
        Category::factory()->create(['slug' => 'archive', 'is_active' => false]);

        foreach (['unknown', 'archive', 'Bad_Slug'] as $slug) {
            $this->get("/api/v1/categories/{$slug}")
                ->assertNotFound()
                ->assertExactJson(['message' => ApiMessages::NOT_FOUND]);
        }
    }

    public function test_the_subcategories_endpoint_lists_active_subcategories_in_order(): void
    {
        $category = Category::factory()->create(['slug' => 'reparation']);
        Subcategory::factory()->for($category)->create(['slug' => 'informatique', 'position' => 30]);
        Subcategory::factory()->for($category)->create(['slug' => 'telephone', 'position' => 10]);
        Subcategory::factory()->for($category)->create(['slug' => 'ancien', 'is_active' => false]);
        Category::factory()->create(['slug' => 'archive', 'is_active' => false]);

        $this->assertSame(
            ['telephone', 'informatique'],
            $this->slugs($this->get('/api/v1/categories/reparation/subcategories')->assertOk())
        );

        $this->get('/api/v1/categories/archive/subcategories')->assertNotFound();
        $this->get('/api/v1/categories/unknown/subcategories')->assertNotFound();
    }

    public function test_cities_list_only_active_cities_and_can_be_filtered_by_country(): void
    {
        City::factory()->create(['slug' => 'lome', 'name' => 'Lome', 'country_code' => 'TG']);
        City::factory()->create(['slug' => 'accra', 'name' => 'Accra', 'country_code' => 'GH']);
        City::factory()->create(['slug' => 'ancienne', 'is_active' => false]);

        $this->assertSame(['accra', 'lome'], $this->slugs($this->get('/api/v1/cities')->assertOk()));
        $this->assertSame(['lome'], $this->slugs($this->get('/api/v1/cities?country=tg')->assertOk()));
    }

    public function test_a_city_is_found_in_the_default_country_unless_another_is_requested(): void
    {
        City::factory()->create(['slug' => 'lome', 'country_code' => 'TG']);
        City::factory()->create(['slug' => 'accra', 'country_code' => 'GH']);
        City::factory()->create(['slug' => 'ancienne', 'is_active' => false, 'country_code' => 'TG']);

        $this->get('/api/v1/cities/lome')->assertOk()->assertJsonPath('data.slug', 'lome');
        $this->get('/api/v1/cities/accra')->assertNotFound();
        $this->get('/api/v1/cities/accra?country=gh')->assertOk()->assertJsonPath('data.country_code', 'GH');
        $this->get('/api/v1/cities/ancienne')->assertNotFound();
    }

    public function test_city_neighborhoods_are_active_sorted_and_paginated(): void
    {
        $city = City::factory()->create(['slug' => 'lome']);
        foreach (['Zeta', 'Alpha', 'Mu', 'Beta', 'Gamma'] as $name) {
            Neighborhood::factory()->for($city)->create(['name' => $name, 'slug' => strtolower($name)]);
        }
        Neighborhood::factory()->for($city)->create(['name' => 'Cache', 'slug' => 'cache', 'is_active' => false]);
        Neighborhood::factory()->for(City::factory()->create(['slug' => 'autre']))->create(['name' => 'Ailleurs', 'slug' => 'ailleurs']);

        $first = $this->get('/api/v1/cities/lome/neighborhoods?per_page=2')->assertOk();
        $this->assertSame(['alpha', 'beta'], $this->slugs($first));
        $this->assertEqualsCanonicalizing(['slug', 'name'], array_keys($first->json('data.0')));
        $first->assertJsonStructure(['data', 'links', 'meta' => ['current_page', 'per_page', 'total']])
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.per_page', 2);
        $this->assertNotNull($first->json('links.next'));

        $last = $this->get('/api/v1/cities/lome/neighborhoods?per_page=2&page=3')->assertOk();
        $this->assertSame(['zeta'], $this->slugs($last));
        $this->assertNull($last->json('links.next'));

        $this->assertCount(5, $this->get('/api/v1/cities/lome/neighborhoods')->json('data'));
    }

    public function test_neighborhoods_of_an_unknown_or_inactive_city_are_a_404(): void
    {
        City::factory()->create(['slug' => 'ancienne', 'is_active' => false]);

        $this->get('/api/v1/cities/unknown/neighborhoods')->assertNotFound();
        $this->get('/api/v1/cities/ancienne/neighborhoods')->assertNotFound();
    }

    public function test_pagination_and_filter_parameters_are_validated(): void
    {
        foreach (['per_page=0', 'per_page=201', 'per_page=abc', 'page=0', 'country=XYZ', 'city=Bad%20Slug'] as $query) {
            $this->get("/api/v1/neighborhoods?{$query}")
                ->assertUnprocessable()
                ->assertJsonPath('message', ApiMessages::INVALID_DATA);
        }
    }

    public function test_the_global_neighborhood_list_filters_by_city_and_hides_inactive_branches(): void
    {
        $lome = City::factory()->create(['slug' => 'lome']);
        $kara = City::factory()->create(['slug' => 'kara']);
        $closed = City::factory()->create(['slug' => 'fermee', 'is_active' => false]);

        Neighborhood::factory()->for($lome)->create(['name' => 'Alpha', 'slug' => 'alpha']);
        Neighborhood::factory()->for($lome)->create(['name' => 'Hidden', 'slug' => 'hidden', 'is_active' => false]);
        Neighborhood::factory()->for($kara)->create(['name' => 'Beta', 'slug' => 'beta']);
        Neighborhood::factory()->for($closed)->create(['name' => 'Gamma', 'slug' => 'gamma']);

        $this->assertSame(['alpha', 'beta'], $this->slugs($this->get('/api/v1/neighborhoods')->assertOk()));
        $this->assertSame(['alpha'], $this->slugs($this->get('/api/v1/neighborhoods?city=lome')->assertOk()));
        $this->assertSame([], $this->get('/api/v1/neighborhoods?city=fermee')->assertOk()->json('data'));
        $this->assertSame(['beta'], $this->slugs($this->get('/api/v1/neighborhoods?city=KARA')->assertOk()));
    }

    public function test_reference_data_comes_from_the_database_seeders(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        $categories = $this->get('/api/v1/categories')->assertOk();
        $this->assertCount(14, $categories->json('data'));
        $this->assertContains('couture', $this->slugs($categories));
        $this->assertCount(3, $this->get('/api/v1/categories/couture')->json('data.subcategories'));

        $cities = $this->get('/api/v1/cities')->assertOk();
        $this->assertSame(['lome'], $this->slugs($cities));
        $this->assertSame("Lom\u{E9}", $cities->json('data.0.name'));

        $this->get('/api/v1/neighborhoods')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_the_catalogue_is_public_and_read_only(): void
    {
        $this->postJson('/api/v1/categories', [])->assertStatus(405);
        $this->deleteJson('/api/v1/cities/lome')->assertStatus(405);
        $this->putJson('/api/v1/neighborhoods', [])->assertStatus(405);

        // A bearer token is not required, and an invalid one is simply ignored on public routes.
        $this->withToken('1|not-a-real-token')->get('/api/v1/categories')->assertOk();
    }

    public function test_successful_reference_responses_are_cacheable_but_errors_are_not(): void
    {
        $cacheControl = (string) $this->get('/api/v1/categories')->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('max-age=300', $cacheControl);
        $this->assertStringContainsString('public', $cacheControl);

        $error = (string) $this->get('/api/v1/categories/unknown')->assertNotFound()->headers->get('Cache-Control');

        $this->assertStringNotContainsString('max-age=300', $error);
    }
}
