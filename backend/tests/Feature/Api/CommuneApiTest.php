<?php

namespace Tests\Feature\Api;

use App\Models\Artisan;
use App\Models\Category;
use App\Models\City;
use App\Models\Commune;
use App\Models\Neighborhood;
use App\Models\NeighborhoodAlias;
use App\Models\User;
use App\Support\ApiMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesApiRequests;
use Tests\TestCase;

class CommuneApiTest extends TestCase
{
    use MakesApiRequests;
    use RefreshDatabase;

    private City $lome;

    private Commune $golfe;

    private Commune $agoe;

    private Neighborhood $inGolfe;

    private Neighborhood $inAgoe;

    private Neighborhood $alone;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lome = City::factory()->create(['slug' => 'lome', 'name' => 'Lome']);
        $this->golfe = Commune::factory()->for($this->lome)->create(['slug' => 'golfe-1', 'name' => 'Golfe 1', 'prefecture' => 'golfe']);
        $this->agoe = Commune::factory()->for($this->lome)->create(['slug' => 'agoe-nyive-1', 'name' => 'Agoe-Nyive 1', 'prefecture' => 'agoe-nyive']);
        $this->inGolfe = Neighborhood::factory()->for($this->lome)->create(['slug' => 'zzz-test-un', 'name' => 'Zzz Test Un', 'commune_id' => $this->golfe->id]);
        Neighborhood::factory()->for($this->lome)->create(['slug' => 'zzz-test-cache', 'name' => 'Zzz Test Cache', 'commune_id' => $this->golfe->id, 'is_active' => false]);
        $this->inAgoe = Neighborhood::factory()->for($this->lome)->create(['slug' => 'zzz-test-deux', 'name' => 'Zzz Test Deux', 'commune_id' => $this->agoe->id]);
        $this->alone = Neighborhood::factory()->for($this->lome)->create(['slug' => 'zzz-test-seul', 'name' => 'Zzz Test Seul']);
        $this->category = Category::factory()->create(['slug' => 'couture', 'name' => 'Couture']);
    }

    private function artisanIn(Neighborhood $neighborhood, string $name): Artisan
    {
        $artisan = Artisan::factory()->for($neighborhood)->validated()->create(['business_name' => $name, 'description' => null])->refresh();
        $artisan->categories()->attach($this->category->id, ['is_primary' => true]);

        return $artisan;
    }

    public function test_the_commune_list_shows_active_communes_in_insertion_order(): void
    {
        Commune::factory()->for($this->lome)->create(['slug' => 'golfe-2', 'name' => 'Golfe 2', 'is_active' => false]);

        $response = $this->getJson('/api/v1/communes')
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta' => ['current_page', 'per_page', 'total']]);

        $this->assertSame(['golfe-1', 'agoe-nyive-1'], array_column($response->json('data'), 'slug'));
        $this->assertEqualsCanonicalizing(['slug', 'name', 'prefecture', 'city'], array_keys($response->json('data.0')));
        $this->assertSame('lome', $response->json('data.0.city.slug'));
    }

    public function test_communes_can_be_filtered_by_city_and_country(): void
    {
        $kara = City::factory()->create(['slug' => 'kara', 'name' => 'Kara']);
        Commune::factory()->for($kara)->create(['slug' => 'zzz-kara', 'name' => 'Zzz Kara']);

        $slugs = fn (string $query): array => $this->getJson('/api/v1/communes'.$query)->assertOk()->json('data.*.slug');

        $this->assertSame(['golfe-1', 'agoe-nyive-1', 'zzz-kara'], $slugs(''));
        $this->assertSame(['golfe-1', 'agoe-nyive-1'], $slugs('?city=lome'));
        $this->assertSame(['zzz-kara'], $slugs('?city=kara'));
        $this->assertSame([], $slugs('?country=GH'));
        $this->assertSame([], $slugs('?city=inconnu'));
    }

    public function test_the_commune_list_is_paginated(): void
    {
        $this->getJson('/api/v1/communes?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_a_commune_is_found_by_slug_and_an_unknown_or_inactive_one_is_a_404(): void
    {
        Commune::factory()->for($this->lome)->create(['slug' => 'golfe-2', 'name' => 'Golfe 2', 'is_active' => false]);

        $this->getJson('/api/v1/communes/golfe-1')
            ->assertOk()
            ->assertJsonPath('data.slug', 'golfe-1')
            ->assertJsonPath('data.prefecture', 'golfe');

        $this->getJson('/api/v1/communes/inconnue')->assertNotFound()->assertExactJson(['message' => ApiMessages::NOT_FOUND]);
        $this->getJson('/api/v1/communes/golfe-2')->assertNotFound();
        $this->getJson('/api/v1/communes/Bad_Slug')->assertNotFound();
    }

    public function test_the_neighborhoods_of_a_commune_are_the_active_ones_with_their_commune(): void
    {
        $response = $this->getJson('/api/v1/communes/golfe-1/neighborhoods')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->assertSame('zzz-test-un', $response->json('data.0.slug'));
        $this->assertSame(['slug' => 'golfe-1', 'name' => 'Golfe 1'], $response->json('data.0.commune'));

        $this->getJson('/api/v1/communes/inconnue/neighborhoods')->assertNotFound();
    }

    public function test_the_parameters_are_validated(): void
    {
        foreach (['per_page=0', 'per_page=51', 'page=0', 'city=Bad%20Slug', 'country=XYZ'] as $query) {
            $this->getJson('/api/v1/communes?'.$query)
                ->assertUnprocessable()
                ->assertJsonPath('message', ApiMessages::INVALID_DATA);
        }
    }

    public function test_the_neighborhood_catalogue_shows_the_commune_only_when_there_is_one(): void
    {
        $rows = collect($this->getJson('/api/v1/neighborhoods?city=lome&per_page=50')->assertOk()->json('data'))->keyBy('slug');

        $this->assertSame('golfe-1', $rows['zzz-test-un']['commune']['slug']);
        $this->assertSame('agoe-nyive-1', $rows['zzz-test-deux']['commune']['slug']);
        $this->assertArrayNotHasKey('commune', $rows['zzz-test-seul']);
        $this->assertArrayNotHasKey('zzz-test-cache', $rows->all());
    }

    public function test_artisan_responses_show_the_commune_of_the_neighborhood(): void
    {
        $withCommune = $this->artisanIn($this->inGolfe, 'Atelier Un');
        $without = $this->artisanIn($this->alone, 'Atelier Seul');

        $items = collect($this->getJson('/api/v1/artisans')->assertOk()->json('data'))->keyBy('uuid');

        $this->assertSame('golfe-1', $items[$withCommune->uuid]['neighborhood']['commune']['slug']);
        $this->assertArrayNotHasKey('commune', $items[$without->uuid]['neighborhood']);

        $this->getJson('/api/v1/artisans/'.$withCommune->uuid)
            ->assertOk()
            ->assertJsonPath('data.neighborhood.commune.slug', 'golfe-1')
            ->assertJsonPath('data.neighborhood.commune.name', 'Golfe 1');

        $this->getJson('/api/v1/artisans/'.$without->uuid)->assertOk()->assertJsonMissingPath('data.neighborhood.commune');
    }

    public function test_the_artisan_list_can_be_filtered_by_commune(): void
    {
        $a = $this->artisanIn($this->inGolfe, 'Atelier Un');
        $b = $this->artisanIn($this->inAgoe, 'Atelier Deux');
        $this->artisanIn($this->alone, 'Atelier Seul');

        $uuids = fn (string $query): array => $this->getJson('/api/v1/artisans'.$query)->assertOk()->json('data.*.uuid');

        $this->assertSame([$a->uuid], $uuids('?commune=golfe-1'));
        $this->assertSame([$b->uuid], $uuids('?commune=agoe-nyive-1'));
        $this->assertSame([], $uuids('?commune=inconnue'));
        $this->assertSame([$a->uuid], $uuids('?commune=golfe-1&neighborhood=zzz-test-un'));
        $this->assertSame([], $uuids('?commune=agoe-nyive-1&neighborhood=zzz-test-un'));
        $this->assertCount(3, $uuids(''));

        $this->getJson('/api/v1/artisans?commune=Bad%20Slug')
            ->assertUnprocessable()
            ->assertJsonPath('message', ApiMessages::INVALID_DATA);
    }

    public function test_the_search_filters_by_commune_and_finds_commune_names_and_aliases(): void
    {
        $a = $this->artisanIn($this->inGolfe, 'Atelier Un');
        $b = $this->artisanIn($this->inAgoe, 'Atelier Deux');
        NeighborhoodAlias::create(['neighborhood_id' => $this->inAgoe->id, 'alias' => 'Zzz Alias Spelling', 'alias_key' => 'zzzaliasspelling']);

        $uuids = fn (string $query): array => $this->getJson('/api/v1/search/artisans'.$query)->assertOk()->json('data.*.uuid');

        $this->assertSame([$a->uuid], $uuids('?commune=golfe-1'));
        $this->assertSame([$a->uuid], $uuids('?q=golfe'));
        $this->assertSame([$b->uuid], $uuids('?q=zzzalias'));
        $this->assertSame([$b->uuid], $uuids('?q=ZZZALIASSPELLING'));
        $this->assertSame([], $uuids('?q=zzzalias&commune=golfe-1'));
    }

    public function test_the_admin_list_shows_and_filters_by_commune(): void
    {
        $a = $this->artisanIn($this->inGolfe, 'Atelier Un');
        $this->artisanIn($this->inAgoe, 'Atelier Deux');
        $admin = User::factory()->admin()->create();

        $response = $this->withApiToken($this->tokenFor($admin))->getJson('/api/v1/admin/artisans?commune=golfe-1')->assertOk();

        $this->assertSame([$a->uuid], $response->json('data.*.uuid'));
        $this->assertSame('golfe-1', $response->json('data.0.neighborhood.commune.slug'));
    }
}
