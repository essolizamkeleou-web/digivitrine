<?php

namespace Tests\Feature;

use App\Models\Artisan;
use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\AssertsDatabaseRejections;
use Tests\TestCase;

class PhotoTest extends TestCase
{
    use AssertsDatabaseRejections;
    use RefreshDatabase;

    private Artisan $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->profile = Artisan::factory()->create();
    }

    /**
     * Minimal valid row for raw inserts (bypasses the model on purpose).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rawPhoto(array $overrides = []): array
    {
        return array_merge([
            'artisan_id' => $this->profile->id,
            'disk' => 'public',
            'path' => 'artisans/test/'.Str::uuid().'.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 120000,
        ], $overrides);
    }

    public function test_artisan_has_many_photos(): void
    {
        Photo::factory()->count(3)->for($this->profile)->create();

        $this->assertCount(3, $this->profile->photos);
        $this->assertTrue($this->profile->photos->first()->artisan->is($this->profile));
    }

    public function test_database_applies_defaults_and_generates_a_uuid(): void
    {
        DB::table('photos')->insert($this->rawPhoto());

        $photo = Photo::firstOrFail();

        $this->assertFalse($photo->is_primary);
        $this->assertTrue($photo->is_active);
        $this->assertSame(0, $photo->position);
        $this->assertTrue(Str::isUuid($photo->uuid));
        $this->assertNull($photo->width);
    }

    public function test_only_one_primary_photo_per_artisan(): void
    {
        $first = Photo::factory()->for($this->profile)->primary()->create();

        $this->assertRejected(
            fn () => Photo::factory()->for($this->profile)->primary()->create(),
            'second primary photo for the same artisan'
        );

        Photo::factory()->for(Artisan::factory()->create())->primary()->create();
        Photo::factory()->for($this->profile)->create();

        // is_primary is not mass assignable: update() would silently ignore it.
        $first->forceFill(['is_primary' => false])->save();
        Photo::factory()->for($this->profile)->primary()->create();

        $this->assertSame(1, $this->profile->photos()->where('is_primary', true)->count());
    }

    public function test_primary_photo_relation_returns_the_primary_one(): void
    {
        Photo::factory()->for($this->profile)->create();
        $primary = Photo::factory()->for($this->profile)->primary()->create();

        $this->assertTrue($this->profile->primaryPhoto->is($primary));
    }

    public function test_only_jpeg_png_and_webp_are_accepted(): void
    {
        foreach (['image/jpeg', 'image/png', 'image/webp'] as $mime) {
            DB::table('photos')->insert($this->rawPhoto(['mime_type' => $mime]));
        }

        $this->assertSame(3, Photo::count());

        foreach (['image/gif', 'image/svg+xml', 'application/pdf', 'text/html'] as $mime) {
            $this->assertRejected(
                fn () => DB::table('photos')->insert($this->rawPhoto(['mime_type' => $mime])),
                "mime type {$mime}"
            );
        }
    }

    public function test_size_must_be_between_one_byte_and_five_megabytes(): void
    {
        $this->assertRejected(fn () => DB::table('photos')->insert($this->rawPhoto(['size_bytes' => 0])), 'empty file');
        $this->assertRejected(fn () => DB::table('photos')->insert($this->rawPhoto(['size_bytes' => 5242881])), 'file above 5 MB');

        DB::table('photos')->insert($this->rawPhoto(['size_bytes' => 5242880]));

        $this->assertDatabaseHas('photos', ['size_bytes' => 5242880]);
    }

    public function test_dimensions_are_optional_but_must_be_positive(): void
    {
        $this->assertRejected(fn () => DB::table('photos')->insert($this->rawPhoto(['width' => 0])), 'zero width');
        $this->assertRejected(fn () => DB::table('photos')->insert($this->rawPhoto(['height' => 0])), 'zero height');

        DB::table('photos')->insert($this->rawPhoto(['width' => 1280, 'height' => 960]));

        $this->assertDatabaseHas('photos', ['width' => 1280, 'height' => 960]);
    }

    public function test_path_cannot_be_blank(): void
    {
        $this->assertRejected(fn () => DB::table('photos')->insert($this->rawPhoto(['path' => '   '])), 'blank path');
    }

    public function test_disk_and_path_are_unique_together(): void
    {
        DB::table('photos')->insert($this->rawPhoto(['path' => 'artisans/test/same.jpg']));

        $this->assertRejected(
            fn () => DB::table('photos')->insert($this->rawPhoto(['path' => 'artisans/test/same.jpg'])),
            'same disk and path twice'
        );

        DB::table('photos')->insert($this->rawPhoto(['disk' => 'cloudinary', 'path' => 'artisans/test/same.jpg']));

        $this->assertSame(2, Photo::count());
    }

    public function test_ownership_primary_and_moderation_fields_are_not_mass_assignable(): void
    {
        $photo = new Photo;

        foreach (['artisan_id', 'uuid', 'is_primary', 'is_active'] as $field) {
            $this->assertFalse($photo->isFillable($field), "{$field} must not be mass assignable");
        }

        foreach (['disk', 'path', 'mime_type', 'size_bytes', 'width', 'height', 'position'] as $field) {
            $this->assertTrue($photo->isFillable($field), "{$field} should be mass assignable");
        }
    }

    public function test_permanently_deleting_an_artisan_removes_its_photos(): void
    {
        $photo = Photo::factory()->for($this->profile)->create();

        $this->profile->forceDelete();

        $this->assertDatabaseMissing('photos', ['id' => $photo->id]);
    }

    public function test_soft_deleting_an_artisan_keeps_its_photos(): void
    {
        $photo = Photo::factory()->for($this->profile)->create();

        $this->profile->delete();

        $this->assertDatabaseHas('photos', ['id' => $photo->id]);
    }
}
