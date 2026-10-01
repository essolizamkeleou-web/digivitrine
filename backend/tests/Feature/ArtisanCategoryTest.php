<?php

namespace Tests\Feature;

use App\Models\Artisan;
use App\Models\Category;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AssertsDatabaseRejections;
use Tests\TestCase;

class ArtisanCategoryTest extends TestCase
{
    use AssertsDatabaseRejections;
    use RefreshDatabase;

    private Artisan $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->profile = Artisan::factory()->create();
    }

    public function test_artisan_can_have_several_categories(): void
    {
        $first = Category::factory()->create();
        $second = Category::factory()->create();

        $this->profile->categories()->attach([$first->id, $second->id]);

        $this->assertCount(2, $this->profile->categories);
    }

    public function test_a_subcategory_of_the_same_category_can_be_attached(): void
    {
        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->for($category)->create();

        $this->profile->categories()->attach($category->id, ['subcategory_id' => $subcategory->id]);

        $this->assertTrue($this->profile->subcategories->first()->is($subcategory));
        $this->assertEquals($subcategory->id, $this->profile->categories->first()->pivot->subcategory_id);
    }

    public function test_subcategory_is_optional(): void
    {
        $category = Category::factory()->create();

        $this->profile->categories()->attach($category->id);

        $this->assertNull($this->profile->categories->first()->pivot->subcategory_id);
        $this->assertCount(0, $this->profile->subcategories);
    }

    public function test_database_rejects_a_subcategory_of_another_category(): void
    {
        $category = Category::factory()->create();
        $otherCategory = Category::factory()->create();
        $foreignSubcategory = Subcategory::factory()->for($otherCategory)->create();

        $this->assertRejected(
            fn () => $this->profile->categories()->attach($category->id, ['subcategory_id' => $foreignSubcategory->id]),
            'subcategory that belongs to a different category'
        );

        $this->assertSame(0, $this->profile->categories()->count());
    }

    public function test_database_rejects_an_unknown_category(): void
    {
        $this->assertRejected(
            fn () => DB::table('artisan_categories')->insert([
                'artisan_id' => $this->profile->id,
                'category_id' => 999999999,
            ]),
            'unknown category'
        );
    }

    public function test_same_category_cannot_be_attached_twice(): void
    {
        $category = Category::factory()->create();
        $this->profile->categories()->attach($category->id);

        $this->assertRejected(
            fn () => $this->profile->categories()->attach($category->id),
            'duplicate artisan/category link'
        );
    }

    public function test_only_one_primary_category_per_artisan(): void
    {
        $first = Category::factory()->create();
        $second = Category::factory()->create();

        $this->profile->categories()->attach($first->id, ['is_primary' => true]);

        $this->assertRejected(
            fn () => $this->profile->categories()->attach($second->id, ['is_primary' => true]),
            'second primary category for the same artisan'
        );

        $this->profile->categories()->attach($second->id, ['is_primary' => false]);

        $this->assertSame(2, $this->profile->categories()->count());
    }

    public function test_two_artisans_can_share_the_same_primary_category(): void
    {
        $category = Category::factory()->create();
        $other = Artisan::factory()->create();

        $this->profile->categories()->attach($category->id, ['is_primary' => true]);
        $other->categories()->attach($category->id, ['is_primary' => true]);

        $this->assertSame(2, $category->artisans()->count());
    }

    public function test_primary_flag_defaults_to_false(): void
    {
        $category = Category::factory()->create();

        $this->profile->categories()->attach($category->id);

        $this->assertFalse((bool) $this->profile->categories->first()->pivot->is_primary);
    }

    public function test_category_in_use_cannot_be_deleted(): void
    {
        $category = Category::factory()->create();
        $this->profile->categories()->attach($category->id);

        $this->assertRejected(fn () => $category->delete(), 'deleting a category linked to an artisan');
    }

    public function test_subcategory_in_use_cannot_be_deleted(): void
    {
        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->for($category)->create();
        $this->profile->categories()->attach($category->id, ['subcategory_id' => $subcategory->id]);

        $this->assertRejected(fn () => $subcategory->delete(), 'deleting a subcategory linked to an artisan');
    }

    public function test_permanently_deleting_an_artisan_removes_its_links_only(): void
    {
        $category = Category::factory()->create();
        $this->profile->categories()->attach($category->id);
        $id = $this->profile->id;

        $this->profile->forceDelete();

        $this->assertDatabaseMissing('artisan_categories', ['artisan_id' => $id]);
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_soft_deleting_an_artisan_keeps_its_links(): void
    {
        $category = Category::factory()->create();
        $this->profile->categories()->attach($category->id);

        $this->profile->delete();

        $this->assertDatabaseHas('artisan_categories', ['artisan_id' => $this->profile->id]);
    }

    public function test_category_and_subcategory_expose_their_artisans(): void
    {
        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->for($category)->create();
        $this->profile->categories()->attach($category->id, ['subcategory_id' => $subcategory->id]);

        $this->assertTrue($category->artisans->first()->is($this->profile));
        $this->assertTrue($subcategory->artisans->first()->is($this->profile));
    }
}
