<?php

namespace Tests\Feature\Api;

use App\Models\Artisan;
use App\Models\Category;
use App\Models\City;
use App\Models\Neighborhood;
use App\Models\OpeningHour;
use App\Models\Service;
use App\Models\Subcategory;
use App\Models\User;
use App\Support\ApiMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\MakesApiRequests;
use Tests\TestCase;

class SearchApiTest extends TestCase
{
    use MakesApiRequests;
    use RefreshDatabase;

    private const URL = '/api/v1/search/artisans';

    private const KOFI = 'Atelier Kofi';

    private const GARAGE = "Garage de l'Ouest";

    private const MENSAH = 'Menuiserie Mensah';

    private Neighborhood $tokoin;

    private Neighborhood $agoe;

    private Category $couture;

    private Category $mecanique;

    private Category $menuiserie;

    private Artisan $kofi;

    private Artisan $garage;

    private Artisan $mensah;

    protected function setUp(): void
    {
        parent::setUp();

        $lome = City::factory()->create(['slug' => 'lome', 'name' => 'Lome']);
        $this->tokoin = Neighborhood::factory()->for($lome)->create(['slug' => 'tokoin', 'name' => 'Tokoin']);
        $this->agoe = Neighborhood::factory()->for($lome)->create(['slug' => 'agoe', 'name' => "Ago\u{E8}"]);
        $this->couture = Category::factory()->create(['slug' => 'couture', 'name' => 'Couture']);
        $this->mecanique = Category::factory()->create(['slug' => 'mecanique', 'name' => "M\u{E9}canique"]);
        $this->menuiserie = Category::factory()->create(['slug' => 'menuiserie', 'name' => 'Menuiserie']);
        Subcategory::factory()->for($this->couture)->create(['slug' => 'hommes', 'name' => 'Hommes']);

        $this->kofi = $this->makeArtisan(self::KOFI, $this->tokoin, $this->couture, ['description' => 'Robes et costumes sur mesure'], 'hommes');
        $this->garage = $this->makeArtisan(self::GARAGE, $this->agoe, $this->mecanique, ['description' => 'Reparation de voitures']);
        $this->mensah = $this->makeArtisan(self::MENSAH, $this->tokoin, $this->menuiserie);

        Service::factory()->for($this->kofi)->create(['name' => 'Retouche de robes']);
        Service::factory()->for($this->kofi)->inactive()->create(['name' => 'Secret inactif']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeArtisan(string $name, Neighborhood $neighborhood, Category $category, array $attributes = [], ?string $subcategory = null, string $state = 'validated'): Artisan
    {
        $artisan = Artisan::factory()->for($neighborhood)->{$state}()->create(
            array_merge(['business_name' => $name, 'description' => null], $attributes)
        )->refresh();

        $sub = $subcategory === null ? null : Subcategory::where('slug', $subcategory)->firstOrFail();
        $artisan->categories()->attach($category->id, ['subcategory_id' => $sub?->id, 'is_primary' => true]);

        return $artisan;
    }

    /**
     * Business names returned by the search, in order.
     *
     * @param  array<string, mixed>  $query
     * @return list<string>
     */
    private function names(array $query = []): array
    {
        $response = $this->getJson(self::URL.'?'.http_build_query($query))->assertOk();

        return collect($response->json('data'))->pluck('business_name')->all();
    }

    public function test_without_parameters_the_search_lists_all_visible_profiles(): void
    {
        $this->makeArtisan('Atelier Brouillon', $this->tokoin, $this->couture, [], null, 'draft');

        $response = $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta' => ['current_page', 'per_page', 'total']])
            ->assertJsonPath('meta.total', 3);

        $this->assertSame([self::KOFI, self::GARAGE, self::MENSAH], collect($response->json('data'))->pluck('business_name')->all());
    }

    public function test_text_search_ignores_case_and_accents(): void
    {
        $this->assertSame([self::GARAGE], $this->names(['q' => 'agoe']));
        $this->assertSame([self::GARAGE], $this->names(['q' => "AGO\u{C8}"]));
        $this->assertSame([self::GARAGE], $this->names(['q' => 'mecanique']));
        $this->assertSame([self::GARAGE], $this->names(['q' => "M\u{C9}CANIQUE"]));
        $this->assertSame([self::GARAGE], $this->names(['q' => "GARAG\u{C9}"]));
        $this->assertSame([self::KOFI], $this->names(['q' => 'ATELIER KOFI']));
    }

    public function test_text_is_searched_in_every_relevant_field(): void
    {
        $this->assertSame([self::KOFI], $this->names(['q' => 'costumes']));
        $this->assertSame([self::KOFI], $this->names(['q' => 'retouche']));
        $this->assertSame([self::KOFI], $this->names(['q' => 'couture']));
        $this->assertSame([self::KOFI], $this->names(['q' => 'hommes']));
        $this->assertSame([self::KOFI, self::MENSAH], $this->names(['q' => 'tokoin']));
        $this->assertSame([self::KOFI, self::GARAGE, self::MENSAH], $this->names(['q' => 'lome']));
        $this->assertSame([self::MENSAH], $this->names(['q' => 'menuiserie']));
        $this->assertSame([self::GARAGE], $this->names(['q' => 'voitures']));
    }

    public function test_inactive_services_are_not_searched(): void
    {
        $this->assertSame([], $this->names(['q' => 'secret']));
    }

    public function test_every_word_must_be_found(): void
    {
        $this->assertSame([self::KOFI], $this->names(['q' => 'couture tokoin']));
        $this->assertSame([], $this->names(['q' => 'couture agoe']));
        $this->assertSame([], $this->names(['q' => 'couture inexistant']));
    }

    public function test_stop_words_and_punctuation_are_ignored(): void
    {
        $this->assertSame([self::KOFI], $this->names(['q' => 'couturier a Tokoin']));
        $this->assertSame([self::GARAGE], $this->names(['q' => 'mecanicien a agoe']));
        $this->assertSame([self::KOFI], $this->names(['q' => "  l'atelier  "]));
        $this->assertSame([self::GARAGE], $this->names(['q' => "Garage, chez Ago\u{E8} !"]));
    }

    public function test_long_words_also_match_by_their_first_five_letters(): void
    {
        $this->assertSame([self::KOFI], $this->names(['q' => 'couturier']));
        $this->assertSame([self::GARAGE], $this->names(['q' => 'mecanicien']));
        $this->assertSame([self::MENSAH], $this->names(['q' => 'menuisier']));
    }

    public function test_filters_combine_with_the_text(): void
    {
        $this->assertSame([self::KOFI], $this->names(['category' => 'couture', 'neighborhood' => 'tokoin']));
        $this->assertSame([], $this->names(['q' => 'mensah', 'category' => 'couture']));
        $this->assertSame([self::GARAGE], $this->names(['city' => 'lome', 'q' => 'garage']));
        $this->assertSame([self::KOFI], $this->names(['subcategory' => 'hommes']));
        $this->assertSame([self::GARAGE], $this->names(['neighborhood' => 'agoe', 'q' => 'garage']));
        $this->assertSame([], $this->names(['city' => 'lome', 'country' => 'GH']));
    }

    public function test_profiles_whose_name_matches_come_first(): void
    {
        $this->makeArtisan('Couture Express', $this->tokoin, $this->couture);

        $this->assertSame(['Couture Express', self::KOFI], $this->names(['q' => 'couture']));
    }

    public function test_the_available_filter_returns_profiles_open_now(): void
    {
        OpeningHour::factory()->for($this->kofi)->forDay(1)->between('08:00', '12:00')->create();
        OpeningHour::factory()->for($this->garage)->forDay(1)->between('14:00', '18:00')->create();
        OpeningHour::factory()->for($this->mensah)->forDay(1)->closed()->create();

        $monday = Carbon::parse('2026-10-05 10:00:00');
        $this->assertSame(1, $monday->dayOfWeekIso);

        $this->travelTo($monday);
        $this->assertSame([self::KOFI], $this->names(['available' => 1]));
        $this->assertSame([self::KOFI], $this->names(['available' => 1, 'q' => 'lome']));
        $this->assertSame([], $this->names(['available' => 1, 'q' => 'agoe']));
        $this->assertCount(3, $this->names(['available' => 0]));
        $this->assertCount(3, $this->names());

        $this->travelTo($monday->copy()->setTime(8, 0));
        $this->assertSame([self::KOFI], $this->names(['available' => 1]));

        $this->travelTo($monday->copy()->setTime(12, 0));
        $this->assertSame([], $this->names(['available' => 1]));

        $this->travelTo($monday->copy()->setTime(14, 0));
        $this->assertSame([self::GARAGE], $this->names(['available' => 1]));

        $this->travelTo($monday->copy()->addDay()->setTime(10, 0));
        $this->assertSame([], $this->names(['available' => 1]));
    }

    public function test_profiles_that_are_not_public_never_appear_even_when_they_match(): void
    {
        $owner = User::factory()->create();

        $this->makeArtisan('Atelier Brouillon', $this->tokoin, $this->couture, [], null, 'draft');
        $this->makeArtisan('Atelier Attente', $this->tokoin, $this->couture, [], null, 'pending');
        $this->makeArtisan('Atelier Refuse', $this->tokoin, $this->couture, [], null, 'rejected');
        $this->makeArtisan('Atelier Suspendu', $this->tokoin, $this->couture, [], null, 'suspended');
        $this->makeArtisan('Atelier Supprime', $this->tokoin, $this->couture)->delete();

        foreach (['brouillon', 'attente', 'refuse', 'suspendu', 'supprime'] as $word) {
            $this->assertSame([], $this->names(['q' => $word]), $word);
        }

        $this->assertSame([self::KOFI], $this->names(['q' => 'atelier']));

        // A token does not change the public directory: even an owner does not see their own draft here.
        $draft = Artisan::factory()->draft()->for($this->tokoin)->ownedBy($owner)->create(['business_name' => 'Atelier Prive'])->refresh();
        $draft->categories()->attach($this->couture->id, ['is_primary' => true]);

        $response = $this->withApiToken($this->tokenFor($owner))->getJson(self::URL.'?q=prive')->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    public function test_results_are_summaries_without_contact_details(): void
    {
        $items = $this->getJson(self::URL.'?q=garage')->assertOk()->json('data');

        $this->assertCount(1, $items);
        $this->assertEqualsCanonicalizing(
            ['uuid', 'business_name', 'description', 'neighborhood', 'category', 'subcategory', 'primary_photo'],
            array_keys($items[0])
        );
        $this->assertSame($this->garage->uuid, $items[0]['uuid']);
        $this->assertSame('mecanique', $items[0]['category']['slug']);
        $this->assertSame('agoe', $items[0]['neighborhood']['slug']);

        foreach (['phone', 'whatsapp_phone', 'whatsapp_url', 'call_url', 'status', 'id', 'user_id'] as $private) {
            $this->assertArrayNotHasKey($private, $items[0]);
        }
    }

    public function test_the_search_is_paginated(): void
    {
        Artisan::factory()->count(25)->validated()->for($this->tokoin)->create();

        $first = $this->getJson(self::URL)->assertOk();
        $this->assertCount(20, $first->json('data'));
        $first->assertJsonPath('meta.total', 28)->assertJsonPath('meta.per_page', 20);

        $this->assertCount(8, $this->getJson(self::URL.'?page=2')->json('data'));
        $this->assertCount(28, $this->getJson(self::URL.'?per_page=50')->json('data'));
        $this->assertCount(5, $this->getJson(self::URL.'?per_page=5')->json('data'));
        $this->assertNotNull($this->getJson(self::URL.'?per_page=5&q=lome')->json('links.next'));
    }

    public function test_special_characters_are_harmless(): void
    {
        $this->assertSame([], $this->names(['q' => "100%_'; DROP TABLE artisans;--"]));
        $this->assertSame([], $this->names(['q' => '"; SELECT pg_sleep(5); --']));
        $this->assertCount(3, $this->names(['q' => '%']));
        $this->assertCount(3, $this->names(['q' => '_']));
        $this->assertCount(3, $this->names(['q' => "'"]));
        $this->assertSame(3, Artisan::count());
    }

    public function test_parameters_are_validated(): void
    {
        $queries = [
            'per_page=0', 'per_page=51', 'per_page=abc', 'page=0', 'available=maybe', 'country=XYZ',
            'category=Bad%20Slug', 'q='.str_repeat('a', 101), 'q[]=a',
        ];

        foreach ($queries as $query) {
            $this->getJson(self::URL.'?'.$query)
                ->assertUnprocessable()
                ->assertJsonPath('message', ApiMessages::INVALID_DATA);
        }
    }

    public function test_the_search_is_public_and_read_only(): void
    {
        $this->getJson(self::URL.'?q=garage')->assertOk();
        $this->withApiToken('1|not-a-real-token')->getJson(self::URL.'?q=garage')->assertOk();

        $this->postJson(self::URL, [])->assertStatus(405);
        $this->deleteJson(self::URL)->assertStatus(405);
    }
}
