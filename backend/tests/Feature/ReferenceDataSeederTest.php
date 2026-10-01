<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\City;
use App\Models\Neighborhood;
use App\Models\Subcategory;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CitySeeder;
use Database\Seeders\NeighborhoodSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\SubcategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ReferenceDataSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Neighborhoods declared in NeighborhoodSeeder (0 until the field list is validated).
     */
    private function declaredNeighborhoodCount(): int
    {
        return array_sum(array_map('count', (new NeighborhoodSeeder)->data()));
    }

    /**
     * Neighborhood seeder fed with FICTIONAL names: only used to test the mechanism,
     * never a list of real neighborhoods.
     *
     * @param  array<string, list<string>>  $data
     */
    private function neighborhoodSeederWith(array $data): NeighborhoodSeeder
    {
        return new class($data) extends NeighborhoodSeeder
        {
            /**
             * @param  array<string, list<string>>  $fake
             */
            public function __construct(private array $fake) {}

            public function data(): array
            {
                return $this->fake;
            }
        };
    }

    /**
     * @return list<int>
     */
    private function tableCounts(): array
    {
        return [City::count(), Neighborhood::count(), Category::count(), Subcategory::count()];
    }

    public function test_database_seeder_creates_the_reference_data(): void
    {
        $this->seed();

        $this->assertSame(1, City::count());
        $this->assertSame(count((new CategorySeeder)->data()), Category::count());
        $this->assertSame(array_sum(array_map('count', (new SubcategorySeeder)->data())), Subcategory::count());
        $this->assertSame($this->declaredNeighborhoodCount(), Neighborhood::count());
    }

    public function test_lome_is_seeded_as_a_city_of_togo(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        $city = City::where('slug', 'lome')->firstOrFail();

        $this->assertSame("Lom\u{E9}", $city->name);
        $this->assertSame('TG', $city->country_code);
        $this->assertTrue($city->is_active);
    }

    public function test_catalogue_contains_the_trades_of_the_specification(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        $expected = [
            'couture', 'menuiserie', 'mecanique', 'coiffure', 'cordonnier', 'reparation', 'peinture',
            'soudure', 'maconnerie', 'commerce', 'restauration', 'imprimerie', 'photographie', 'decoration',
        ];

        $this->assertSame(14, Category::count());

        foreach ($expected as $slug) {
            $this->assertTrue(Category::where('slug', $slug)->exists(), "Missing category {$slug}");
        }

        $this->assertSame("M\u{E9}canique", Category::where('slug', 'mecanique')->firstOrFail()->name);
        $this->assertSame(
            range(10, 140, 10),
            Category::orderBy('position')->pluck('position')->all()
        );
    }

    public function test_subcategories_belong_to_their_category(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        $expected = [
            'couture' => ['vetements-homme', 'vetements-femme', 'vetements-enfant'],
            'mecanique' => ['automobile', 'moto'],
            'reparation' => ['telephone', 'electromenager', 'informatique'],
        ];

        foreach ($expected as $categorySlug => $slugs) {
            $category = Category::where('slug', $categorySlug)->firstOrFail();

            $this->assertEqualsCanonicalizing($slugs, $category->subcategories->pluck('slug')->all());

            foreach ($category->subcategories as $subcategory) {
                $this->assertTrue($subcategory->category->is($category));
            }
        }

        $this->assertSame(8, Subcategory::count());
        $this->assertSame(0, Category::where('slug', 'menuiserie')->firstOrFail()->subcategories()->count());
    }

    public function test_seeding_twice_creates_no_duplicates(): void
    {
        $this->seed();

        $counts = $this->tableCounts();
        $categoryIds = Category::orderBy('id')->pluck('id')->all();
        $subcategoryIds = Subcategory::orderBy('id')->pluck('id')->all();

        $this->seed();

        $this->assertSame($counts, $this->tableCounts());
        $this->assertSame($categoryIds, Category::orderBy('id')->pluck('id')->all());
        $this->assertSame($subcategoryIds, Subcategory::orderBy('id')->pluck('id')->all());
    }

    public function test_seeders_never_overwrite_manual_changes(): void
    {
        $this->seed();

        Category::where('slug', 'couture')->firstOrFail()->update(['name' => 'Couture et retouches', 'is_active' => false]);
        City::where('slug', 'lome')->firstOrFail()->update(['is_active' => false]);

        $this->seed();

        $category = Category::where('slug', 'couture')->firstOrFail();
        $this->assertSame('Couture et retouches', $category->name);
        $this->assertFalse($category->is_active);
        $this->assertFalse(City::where('slug', 'lome')->firstOrFail()->is_active);
    }

    public function test_a_missing_row_is_recreated_without_touching_the_others(): void
    {
        $this->seed();

        $automobileId = Subcategory::where('slug', 'automobile')->firstOrFail()->id;
        Subcategory::where('slug', 'moto')->delete();

        $this->seed();

        $this->assertTrue(Subcategory::where('slug', 'moto')->exists());
        $this->assertSame($automobileId, Subcategory::where('slug', 'automobile')->firstOrFail()->id);
        $this->assertSame(8, Subcategory::count());
    }

    public function test_neighborhoods_are_linked_to_their_city_and_seeded_only_once(): void
    {
        $this->seed(CitySeeder::class);

        $seeder = $this->neighborhoodSeederWith(['TG:lome' => ['Quartier Fictif Un', 'Quartier Fictif Deux']]);
        $seeder->run();
        $seeder->run();

        $city = City::where('slug', 'lome')->firstOrFail();

        $this->assertSame(2, Neighborhood::count());
        $this->assertEqualsCanonicalizing(
            ['quartier-fictif-un', 'quartier-fictif-deux'],
            $city->neighborhoods->pluck('slug')->all()
        );
        $this->assertTrue($city->neighborhoods->first()->city->is($city));
    }

    public function test_neighborhood_seeder_with_an_empty_list_does_nothing(): void
    {
        $this->neighborhoodSeederWith(['TG:lome' => []])->run();

        $this->assertSame(0, Neighborhood::count());
    }

    public function test_neighborhood_seeder_refuses_an_unknown_city(): void
    {
        $this->seed(CitySeeder::class);

        $this->expectException(RuntimeException::class);

        $this->neighborhoodSeederWith(['TG:ville-inconnue' => ['Quartier Fictif']])->run();
    }

    public function test_subcategory_seeder_requires_the_categories_to_exist(): void
    {
        $this->expectException(RuntimeException::class);

        (new SubcategorySeeder)->run();
    }
}
