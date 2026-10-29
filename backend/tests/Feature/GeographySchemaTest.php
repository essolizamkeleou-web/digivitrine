<?php

namespace Tests\Feature;

use App\Enums\GeoVerification;
use App\Enums\NeighborhoodKind;
use App\Models\City;
use App\Models\Commune;
use App\Models\Neighborhood;
use App\Models\NeighborhoodAlias;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GeographySchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A statement the database must refuse. The savepoint keeps the test transaction usable afterwards.
     */
    private function assertRejected(callable $statement): void
    {
        try {
            DB::transaction($statement);
        } catch (QueryException) {
            $this->assertTrue(true);

            return;
        }

        $this->fail('The database should have rejected this statement.');
    }

    public function test_a_commune_belongs_to_a_city_and_has_neighborhoods(): void
    {
        $city = City::factory()->create();
        $commune = Commune::factory()->for($city)->create();
        $neighborhood = Neighborhood::factory()->for($city)->create(['commune_id' => $commune->id]);

        $this->assertTrue($commune->city->is($city));
        $this->assertTrue($city->communes->first()->is($commune));
        $this->assertTrue($commune->neighborhoods->first()->is($neighborhood));
        $this->assertTrue($neighborhood->commune->is($commune));
    }

    public function test_a_commune_slug_is_unique_per_city_only(): void
    {
        $city = City::factory()->create();
        $other = City::factory()->create();
        Commune::factory()->for($city)->create(['slug' => 'zzz-test']);

        $this->assertRejected(fn () => Commune::factory()->for($city)->create(['slug' => 'zzz-test']));

        Commune::factory()->for($other)->create(['slug' => 'zzz-test']);
        $this->assertSame(2, Commune::where('slug', 'zzz-test')->count());
    }

    public function test_a_commune_slug_format_is_checked(): void
    {
        $city = City::factory()->create();

        $this->assertRejected(fn () => Commune::factory()->for($city)->create(['slug' => 'Bad Slug']));
    }

    public function test_the_commune_of_a_neighborhood_must_belong_to_the_same_city(): void
    {
        $city = City::factory()->create();
        $foreign = Commune::factory()->for(City::factory()->create())->create();

        $this->assertRejected(fn () => Neighborhood::factory()->for($city)->create(['commune_id' => $foreign->id]));
    }

    public function test_a_neighborhood_without_commune_stays_valid_with_safe_defaults(): void
    {
        $neighborhood = Neighborhood::factory()->for(City::factory()->create())->create()->refresh();

        $this->assertNull($neighborhood->commune_id);
        $this->assertSame(NeighborhoodKind::Unspecified, $neighborhood->kind);
        $this->assertSame(GeoVerification::ToVerify, $neighborhood->verification);
        $this->assertNull($neighborhood->source_ref);
    }

    public function test_kind_and_verification_are_checked_by_the_database(): void
    {
        $city = City::factory()->create();
        $row = ['city_id' => $city->id, 'slug' => 'zzz-test', 'name' => 'Zzz Test'];

        $this->assertRejected(fn () => DB::table('neighborhoods')->insert($row + ['kind' => 'ville']));
        $this->assertRejected(fn () => DB::table('neighborhoods')->insert($row + ['verification' => 'peut-etre']));

        DB::table('neighborhoods')->insert($row + ['kind' => 'village', 'verification' => 'verifie']);
        $this->assertSame(1, Neighborhood::count());
    }

    public function test_a_commune_with_neighborhoods_cannot_be_deleted(): void
    {
        $city = City::factory()->create();
        $commune = Commune::factory()->for($city)->create();
        Neighborhood::factory()->for($city)->create(['commune_id' => $commune->id]);

        $this->assertRejected(fn () => $commune->delete());
        $this->assertSame(1, Commune::count());
    }

    public function test_a_neighborhood_slug_stays_unique_in_the_whole_city(): void
    {
        $city = City::factory()->create();
        $one = Commune::factory()->for($city)->create();
        $two = Commune::factory()->for($city)->create();
        Neighborhood::factory()->for($city)->create(['slug' => 'zzz-test', 'commune_id' => $one->id]);

        $this->assertRejected(fn () => Neighborhood::factory()->for($city)->create(['slug' => 'zzz-test', 'commune_id' => $two->id]));
    }

    public function test_an_alias_key_is_unique_per_neighborhood_but_shareable_between_neighborhoods(): void
    {
        $city = City::factory()->create();
        $one = Neighborhood::factory()->for($city)->create();
        $two = Neighborhood::factory()->for($city)->create();
        NeighborhoodAlias::create(['neighborhood_id' => $one->id, 'alias' => 'Zzz Test', 'alias_key' => 'zzztest']);

        $this->assertRejected(fn () => NeighborhoodAlias::create(['neighborhood_id' => $one->id, 'alias' => 'Zzz-Test', 'alias_key' => 'zzztest']));

        NeighborhoodAlias::create(['neighborhood_id' => $two->id, 'alias' => 'Zzz Test', 'alias_key' => 'zzztest']);
        $this->assertSame(2, NeighborhoodAlias::count());
        $this->assertTrue($one->aliases->first()->neighborhood->is($one));
    }

    public function test_an_alias_key_format_is_checked(): void
    {
        $neighborhood = Neighborhood::factory()->for(City::factory()->create())->create();

        $this->assertRejected(fn () => NeighborhoodAlias::create(['neighborhood_id' => $neighborhood->id, 'alias' => 'Zzz Test', 'alias_key' => 'Zzz Test']));
    }

    public function test_deleting_a_neighborhood_deletes_its_aliases(): void
    {
        $neighborhood = Neighborhood::factory()->for(City::factory()->create())->create();
        NeighborhoodAlias::create(['neighborhood_id' => $neighborhood->id, 'alias' => 'Zzz Test', 'alias_key' => 'zzztest']);

        $neighborhood->delete();

        $this->assertSame(0, NeighborhoodAlias::count());
    }
}
