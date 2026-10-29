<?php

namespace Tests\Feature;

use App\Enums\GeoVerification;
use App\Enums\NeighborhoodKind;
use App\Models\City;
use App\Models\Commune;
use App\Models\Neighborhood;
use App\Models\NeighborhoodAlias;
use App\Services\GeographyLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class GeographyLoaderTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'digivitrine-geo-'.uniqid();
        $this->writeData();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    /**
     * @return list<string>
     */
    private function communeSlugs(): array
    {
        $slugs = [];

        foreach (range(1, 7) as $number) {
            $slugs[] = "golfe-{$number}";
        }

        foreach (range(1, 6) as $number) {
            $slugs[] = "agoe-nyive-{$number}";
        }

        return $slugs;
    }

    /**
     * Writes a complete data directory (13 communes) with fictitious entries only.
     *
     * @param  array<string, list<array<string, mixed>>>  $neighborhoods  commune slug => entries
     */
    private function writeData(array $neighborhoods = []): void
    {
        $base = $this->dir.'/togo/lome';
        File::ensureDirectoryExists($base.'/neighborhoods');

        File::put($this->dir.'/sources.json', json_encode(['sources' => [
            ['id' => 'src-t1', 'tier' => 2, 'located' => true],
            ['id' => 'src-t2', 'tier' => 3, 'located' => true],
            ['id' => 'src-t3', 'tier' => 5, 'located' => true],
        ]]));

        File::put($base.'/communes.json', json_encode([
            'city' => ['country_code' => 'TG', 'slug' => 'lome'],
            'communes' => array_map(fn (string $slug) => [
                'slug' => $slug,
                'name' => 'Zzz '.$slug,
                'prefecture' => str_starts_with($slug, 'golfe') ? 'golfe' : 'agoe-nyive',
                'aliases' => [],
                'sources' => ['src-t3'],
                'verification' => 'a_verifier',
            ], $this->communeSlugs()),
        ]));

        foreach ($this->communeSlugs() as $slug) {
            $list = $neighborhoods[$slug] ?? [];

            File::put($base.'/neighborhoods/'.$slug.'.json', json_encode([
                'commune' => $slug,
                'list_status' => $list === [] ? 'a_verifier' : 'partiel',
                'declared_count' => null,
                'neighborhoods' => $list,
            ]));
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function entry(string $commune, string $name, array $extra = []): array
    {
        return array_merge([
            'name' => $name,
            'commune' => $commune,
            'slug' => Str::slug($name),
            'kind' => 'non_precise',
            'aliases' => [],
            'sources' => ['src-t3'],
            'verification' => 'a_verifier',
            'distinct_from' => [],
        ], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function proof(): array
    {
        return ['verification' => 'verifie', 'sources' => ['src-t1', 'src-t2'], 'verified_by' => 'relecteur', 'verified_on' => '2026-01-01'];
    }

    private function makeCity(): City
    {
        return City::factory()->create(['country_code' => 'TG', 'slug' => 'lome', 'name' => 'Lome']);
    }

    private function loader(): GeographyLoader
    {
        return app(GeographyLoader::class);
    }

    public function test_the_real_data_files_produce_a_valid_plan(): void
    {
        $this->makeCity();

        $result = $this->loader()->plan();

        $this->assertSame([], $result['errors']);
        $this->assertCount(13, array_filter($result['plan']['communes'], fn (array $c) => ! $c['exists']));
    }

    public function test_the_plan_writes_nothing(): void
    {
        $this->makeCity();

        $result = $this->loader()->plan($this->dir);

        $this->assertSame([], $result['errors']);
        $this->assertSame(0, Commune::count());
        $this->assertSame(0, Neighborhood::count());
    }

    public function test_applying_creates_the_communes_once(): void
    {
        $city = $this->makeCity();

        $first = $this->loader()->apply($this->dir);
        $second = $this->loader()->apply($this->dir);

        $this->assertSame([], $first['errors']);
        $this->assertSame(['communes' => 13, 'neighborhoods' => 0, 'aliases' => 0], $first['created']);
        $this->assertSame(['communes' => 0, 'neighborhoods' => 0, 'aliases' => 0], $second['created']);
        $this->assertSame(13, Commune::where('city_id', $city->id)->count());
        $this->assertSame('golfe', Commune::where('slug', 'golfe-3')->firstOrFail()->prefecture);
    }

    public function test_an_unknown_city_blocks_everything(): void
    {
        $result = $this->loader()->apply($this->dir);

        $this->assertNotEmpty($result['errors']);
        $this->assertNull($result['created']);
        $this->assertSame(0, Commune::count());
    }

    public function test_neighborhoods_are_loaded_inactive_until_verified(): void
    {
        $this->makeCity();
        $this->writeData([
            'golfe-1' => [
                $this->entry('golfe-1', 'Zzz Test Un', ['kind' => 'quartier', 'aliases' => ['Zzz-Test 1']]),
                $this->entry('golfe-1', 'Zzz Test Deux', array_merge($this->proof(), ['kind' => 'village'])),
            ],
            'golfe-2' => [$this->entry('golfe-2', 'Zzz Test Trois')],
        ]);

        $result = $this->loader()->apply($this->dir);

        $this->assertSame([], $result['errors']);
        $this->assertSame(['communes' => 13, 'neighborhoods' => 3, 'aliases' => 1], $result['created']);

        $one = Neighborhood::where('slug', 'zzz-test-un')->firstOrFail();
        $this->assertFalse($one->is_active);
        $this->assertSame(GeoVerification::ToVerify, $one->verification);
        $this->assertSame(NeighborhoodKind::Quarter, $one->kind);
        $this->assertSame('golfe-1', $one->commune->slug);
        $this->assertSame('src-t3', $one->source_ref);
        $this->assertSame(['zzztest1'], $one->aliases->pluck('alias_key')->all());

        $two = Neighborhood::where('slug', 'zzz-test-deux')->firstOrFail();
        $this->assertTrue($two->is_active);
        $this->assertSame(GeoVerification::Verified, $two->verification);
        $this->assertSame(NeighborhoodKind::Village, $two->kind);
        $this->assertSame('src-t1, src-t2', $two->source_ref);

        $three = Neighborhood::where('slug', 'zzz-test-trois')->firstOrFail();
        $this->assertSame('golfe-2', $three->commune->slug);
        $this->assertFalse($three->is_active);
    }

    public function test_a_slug_already_used_by_another_commune_stops_everything(): void
    {
        $city = $this->makeCity();
        $other = Commune::factory()->for($city)->create(['slug' => 'golfe-2', 'name' => 'Zzz G2']);
        Neighborhood::factory()->for($city)->create(['name' => 'Zzz Test Un', 'slug' => 'zzz-test-un', 'commune_id' => $other->id]);
        $this->writeData(['golfe-1' => [$this->entry('golfe-1', 'Zzz Test Un')]]);

        $result = $this->loader()->apply($this->dir);

        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('collision', implode(' ', $result['errors']));
        $this->assertNull($result['created']);
        $this->assertSame(1, Commune::count());
        $this->assertSame(1, Neighborhood::count());
    }

    public function test_a_slug_used_by_a_neighborhood_without_commune_stops_everything(): void
    {
        $city = $this->makeCity();
        Neighborhood::factory()->for($city)->create(['name' => 'Zzz Test Un', 'slug' => 'zzz-test-un']);
        $this->writeData(['golfe-1' => [$this->entry('golfe-1', 'Zzz Test Un')]]);

        $result = $this->loader()->apply($this->dir);

        $this->assertNotEmpty($result['errors']);
        $this->assertSame(0, Commune::count());
        $this->assertSame(1, Neighborhood::count());
    }

    public function test_existing_rows_are_never_modified(): void
    {
        $this->makeCity();
        $this->writeData(['golfe-1' => [$this->entry('golfe-1', 'Zzz Test Un')]]);
        $this->loader()->apply($this->dir);

        $neighborhood = Neighborhood::where('slug', 'zzz-test-un')->firstOrFail();
        $neighborhood->name = 'Zzz Edited';
        $neighborhood->is_active = true;
        $neighborhood->save();

        $second = $this->loader()->apply($this->dir);

        $this->assertSame([], $second['errors']);
        $this->assertSame(0, $second['created']['neighborhoods']);
        $neighborhood->refresh();
        $this->assertSame('Zzz Edited', $neighborhood->name);
        $this->assertTrue($neighborhood->is_active);
    }

    public function test_invalid_files_block_everything(): void
    {
        $this->makeCity();
        $this->writeData([
            'golfe-1' => [$this->entry('golfe-1', 'Zzz Test Un')],
            'golfe-2' => [$this->entry('golfe-2', 'Zzz Test Un')],
        ]);

        $result = $this->loader()->apply($this->dir);

        $this->assertStringContainsString('collision de slug', implode(' ', $result['errors']));
        $this->assertSame(0, Commune::count());
        $this->assertSame(0, Neighborhood::count());
    }

    public function test_aliases_added_later_are_loaded_without_touching_the_neighborhood(): void
    {
        $this->makeCity();
        $this->writeData(['golfe-1' => [$this->entry('golfe-1', 'Zzz Test Un')]]);
        $this->loader()->apply($this->dir);

        $this->writeData(['golfe-1' => [$this->entry('golfe-1', 'Zzz Test Un', ['aliases' => ['Zzz Test 1', 'ZZZ-TEST-UN']])]]);
        $second = $this->loader()->apply($this->dir);
        $third = $this->loader()->apply($this->dir);

        $this->assertSame(['communes' => 0, 'neighborhoods' => 0, 'aliases' => 1], $second['created']);
        $this->assertSame(0, $third['created']['aliases']);
        $this->assertSame(1, NeighborhoodAlias::count());
        $this->assertSame(1, Neighborhood::count());
    }

    public function test_the_command_simulates_by_default(): void
    {
        $this->makeCity();

        $this->artisan('geography:load', ['--path' => $this->dir])->assertExitCode(0);

        $this->assertSame(0, Commune::count());
    }

    public function test_the_command_writes_only_with_apply(): void
    {
        $this->makeCity();

        $this->artisan('geography:load', ['--path' => $this->dir, '--apply' => true])->assertExitCode(0);

        $this->assertSame(13, Commune::count());
    }

    public function test_the_command_fails_without_writing_when_there_are_errors(): void
    {
        $this->artisan('geography:load', ['--path' => $this->dir, '--apply' => true])->assertExitCode(1);

        $this->assertSame(0, Commune::count());
    }
}
