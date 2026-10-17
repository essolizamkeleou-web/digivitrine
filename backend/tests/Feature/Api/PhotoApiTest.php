<?php

namespace Tests\Feature\Api;

use App\Models\Artisan;
use App\Models\Category;
use App\Models\City;
use App\Models\Neighborhood;
use App\Models\Photo;
use App\Models\User;
use App\Support\ApiMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\MakesApiRequests;
use Tests\TestCase;

class PhotoApiTest extends TestCase
{
    use MakesApiRequests;
    use RefreshDatabase;

    private const KEYS = ['uuid', 'url', 'width', 'height', 'is_primary', 'is_active', 'position'];

    private string $disk;

    private Neighborhood $neighborhood;

    private Category $category;

    private User $owner;

    private Artisan $artisan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disk = (string) config('digivitrine.photos_disk');
        Storage::fake($this->disk);

        $city = City::factory()->create(['slug' => 'lome']);
        $this->neighborhood = Neighborhood::factory()->for($city)->create(['slug' => 'quartier-un']);
        $this->category = Category::factory()->create(['slug' => 'couture']);
        $this->owner = User::factory()->create();
        $this->artisan = $this->makeArtisan('draft', $this->owner);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeArtisan(string $state, ?User $owner = null, array $attributes = []): Artisan
    {
        $factory = Artisan::factory()->for($this->neighborhood)->{$state}();

        if ($owner !== null) {
            $factory = $factory->ownedBy($owner);
        }

        $artisan = $factory->create($attributes)->refresh();
        $artisan->categories()->attach($this->category->id, ['is_primary' => true]);

        return $artisan;
    }

    private function makePhoto(Artisan $artisan, int $position, bool $primary = false, bool $active = true): Photo
    {
        return Photo::factory()->for($artisan)->create([
            'position' => $position,
            'is_primary' => $primary,
            'is_active' => $active,
        ])->refresh();
    }

    private function asUser(User $user): static
    {
        return $this->withApiToken($this->tokenFor($user));
    }

    private function url(Artisan $artisan, ?Photo $photo = null): string
    {
        return '/api/v1/artisans/'.$artisan->uuid.'/photos'.($photo === null ? '' : '/'.$photo->uuid);
    }

    private function image(string $name = 'photo.jpg', int $width = 800, int $height = 600): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, $height);
    }

    private function upload(User $user, Artisan $artisan, ?UploadedFile $file = null): TestResponse
    {
        return $this->asUser($user)->post($this->url($artisan), ['photo' => $file ?? $this->image()], ['Accept' => 'application/json']);
    }

    // ------------------------------------------------------------------ upload

    public function test_an_owner_uploads_a_photo_that_is_stored_as_webp(): void
    {
        $data = $this->upload($this->owner, $this->artisan)->assertCreated()->json('data');

        $this->assertEqualsCanonicalizing(self::KEYS, array_keys($data));
        $this->assertTrue($data['is_primary']);
        $this->assertTrue($data['is_active']);
        $this->assertSame(0, $data['position']);
        $this->assertSame(800, $data['width']);
        $this->assertSame(600, $data['height']);

        $photo = Photo::firstOrFail();

        $this->assertSame($data['uuid'], $photo->uuid);
        $this->assertSame($this->artisan->id, $photo->artisan_id);
        $this->assertSame($this->disk, $photo->disk);
        $this->assertSame("artisans/{$this->artisan->uuid}/{$photo->uuid}.webp", $photo->path);
        $this->assertSame('image/webp', $photo->mime_type);
        $this->assertStringEndsWith($photo->path, $data['url']);

        Storage::disk($this->disk)->assertExists($photo->path);
        $stored = Storage::disk($this->disk)->get($photo->path);
        $this->assertSame('WEBP', substr($stored, 8, 4));
        $this->assertSame(strlen($stored), $photo->size_bytes);
    }

    public function test_the_response_never_exposes_the_disk_the_path_or_internal_ids(): void
    {
        $response = $this->upload($this->owner, $this->artisan)->assertCreated();

        foreach (['id', 'artisan_id', 'disk', 'path', 'mime_type', 'size_bytes', 'original_name'] as $key) {
            $response->assertJsonMissingPath("data.{$key}");
        }
    }

    public function test_the_client_file_name_and_extension_are_never_used_for_storage(): void
    {
        $this->upload($this->owner, $this->artisan, $this->image('../../evil.php.jpg'))->assertCreated();

        $photo = Photo::firstOrFail();

        $this->assertStringNotContainsString('evil', $photo->path);
        $this->assertStringEndsWith('.webp', $photo->path);
        $this->assertSame('evil.php.jpg', $photo->original_name);
    }

    public function test_the_type_is_checked_on_the_content_not_on_the_extension(): void
    {
        $jpeg = $this->image('source.jpg');
        $content = (string) file_get_contents((string) $jpeg->getRealPath());

        $this->upload($this->owner, $this->artisan, UploadedFile::fake()->createWithContent('renamed.png', $content))->assertCreated();

        $this->assertSame('image/webp', Photo::firstOrFail()->mime_type);
    }

    public function test_disguised_and_unsupported_files_are_rejected(): void
    {
        $files = [
            UploadedFile::fake()->createWithContent('evil.jpg', "<?php echo 'x'; ?>"),
            UploadedFile::fake()->createWithContent('vector.png', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>'),
            UploadedFile::fake()->createWithContent('doc.jpg', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n"),
            UploadedFile::fake()->image('animation.gif', 400, 400),
        ];

        foreach ($files as $file) {
            $this->upload($this->owner, $this->artisan, $file)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['photo']);
        }

        $this->assertSame(0, Photo::count());
        $this->assertSame([], Storage::disk($this->disk)->allFiles());
    }

    public function test_size_and_dimension_limits_are_enforced(): void
    {
        $this->upload($this->owner, $this->artisan, $this->image('heavy.jpg')->size(6000))
            ->assertUnprocessable()->assertJsonValidationErrors(['photo']);

        $this->upload($this->owner, $this->artisan, $this->image('tiny.jpg', 100, 100))
            ->assertUnprocessable()->assertJsonValidationErrors(['photo']);

        $this->upload($this->owner, $this->artisan, $this->image('wide.jpg', 5001, 300))
            ->assertUnprocessable()->assertJsonValidationErrors(['photo']);

        $this->assertSame(0, Photo::count());
    }

    public function test_a_missing_or_malformed_photo_field_is_rejected(): void
    {
        $this->asUser($this->owner)->post($this->url($this->artisan), [], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['photo'])
            ->assertJsonPath('message', ApiMessages::INVALID_DATA);

        $this->asUser($this->owner)->post($this->url($this->artisan), ['photo' => 'not-a-file'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['photo']);

        $this->assertSame(0, Photo::count());
    }

    public function test_the_first_photo_is_primary_and_the_next_ones_are_not(): void
    {
        $first = $this->upload($this->owner, $this->artisan)->assertCreated()->json('data');
        $second = $this->upload($this->owner, $this->artisan)->assertCreated()->json('data');

        $this->assertTrue($first['is_primary']);
        $this->assertFalse($second['is_primary']);
        $this->assertSame(0, $first['position']);
        $this->assertSame(1, $second['position']);
        $this->assertSame(1, Photo::where('is_primary', true)->count());
    }

    public function test_the_gallery_is_limited_and_a_refused_upload_stores_nothing(): void
    {
        $this->assertSame(6, config('digivitrine.photos_max_per_artisan'));

        config(['digivitrine.photos_max_per_artisan' => 2]);

        $this->upload($this->owner, $this->artisan)->assertCreated();
        $this->upload($this->owner, $this->artisan)->assertCreated();
        $this->upload($this->owner, $this->artisan)
            ->assertStatus(409)
            ->assertExactJson(['message' => ApiMessages::PHOTO_LIMIT]);

        $this->assertSame(2, Photo::count());
        $this->assertCount(2, Storage::disk($this->disk)->allFiles());
    }

    public function test_the_limit_is_per_profile(): void
    {
        config(['digivitrine.photos_max_per_artisan' => 1]);

        $other = $this->makeArtisan('draft', User::factory()->create());

        $this->upload($this->owner, $this->artisan)->assertCreated();
        $this->upload($other->owner, $other)->assertCreated();

        $this->assertSame(2, Photo::count());
    }

    // ------------------------------------------------------------------ authorization

    public function test_only_people_who_can_edit_the_profile_can_upload(): void
    {
        $stranger = User::factory()->create();
        $validated = $this->makeArtisan('validated', User::factory()->create());

        $this->upload($stranger, $this->artisan)->assertNotFound();
        $this->upload($stranger, $validated)
            ->assertForbidden()
            ->assertExactJson(['message' => ApiMessages::FORBIDDEN]);

        $suspendedOwner = User::factory()->create();
        $suspended = $this->makeArtisan('suspended', $suspendedOwner);
        $this->upload($suspendedOwner, $suspended)->assertForbidden();

        $this->assertSame(0, Photo::count());
    }

    public function test_the_creating_agent_and_the_staff_can_upload(): void
    {
        $agent = User::factory()->fieldAgent()->create();
        $unclaimed = $this->makeArtisan('draft', null, ['created_by' => $agent->id]);

        $this->upload($agent, $unclaimed)->assertCreated();
        $this->upload(User::factory()->admin()->create(), $this->artisan)->assertCreated();
        $this->upload(User::factory()->moderator()->create(), $this->artisan)->assertCreated();

        $this->assertSame(3, Photo::count());
    }

    public function test_uploading_requires_an_authenticated_active_account(): void
    {
        $this->asGuest()->post($this->url($this->artisan), ['photo' => $this->image()], ['Accept' => 'application/json'])
            ->assertUnauthorized();

        $inactive = User::factory()->inactive()->create();
        $draft = $this->makeArtisan('draft', $inactive);

        $this->upload($inactive, $draft)
            ->assertForbidden()
            ->assertExactJson(['message' => ApiMessages::ACCOUNT_DISABLED]);

        $this->assertSame(0, Photo::count());
    }

    public function test_a_stranger_gets_no_validation_feedback_on_a_profile_they_cannot_see(): void
    {
        $invalid = UploadedFile::fake()->createWithContent('evil.jpg', "<?php echo 'x'; ?>");

        $this->upload(User::factory()->create(), $this->artisan, $invalid)->assertNotFound();
    }

    // ------------------------------------------------------------------ list

    public function test_the_public_gallery_shows_only_active_photos_in_order(): void
    {
        $artisan = $this->makeArtisan('validated', User::factory()->create());
        $second = $this->makePhoto($artisan, 2);
        $first = $this->makePhoto($artisan, 1, true);
        $this->makePhoto($artisan, 0, false, false);

        $response = $this->asGuest()->getJson($this->url($artisan))->assertOk();

        $this->assertSame([$first->uuid, $second->uuid], array_column($response->json('data'), 'uuid'));
        $this->assertEqualsCanonicalizing(self::KEYS, array_keys($response->json('data.0')));
    }

    public function test_the_owner_and_the_staff_also_see_hidden_photos(): void
    {
        $owner = User::factory()->create();
        $artisan = $this->makeArtisan('validated', $owner);
        $this->makePhoto($artisan, 1, true);
        $hidden = $this->makePhoto($artisan, 2, false, false);

        foreach ([$owner, User::factory()->admin()->create()] as $privileged) {
            $data = $this->asUser($privileged)->getJson($this->url($artisan))->assertOk()->json('data');

            $this->assertCount(2, $data);
            $this->assertContains($hidden->uuid, array_column($data, 'uuid'));
        }

        $this->assertCount(1, $this->asUser(User::factory()->create())->getJson($this->url($artisan))->json('data'));
    }

    public function test_the_gallery_of_a_profile_that_is_not_public_is_a_404_for_strangers(): void
    {
        $this->makePhoto($this->artisan, 0, true);

        $this->asGuest()->getJson($this->url($this->artisan))
            ->assertNotFound()
            ->assertExactJson(['message' => ApiMessages::NOT_FOUND]);
        $this->asUser(User::factory()->create())->getJson($this->url($this->artisan))->assertNotFound();
        $this->asUser($this->owner)->getJson($this->url($this->artisan))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_uploaded_photos_appear_in_the_public_profile(): void
    {
        $owner = User::factory()->create();
        $artisan = $this->makeArtisan('validated', $owner);

        $this->upload($owner, $artisan)->assertCreated();

        $data = $this->asGuest()->getJson('/api/v1/artisans/'.$artisan->uuid)->assertOk()->json('data');

        $this->assertCount(1, $data['photos']);
        $this->assertTrue($data['photos'][0]['is_primary']);
    }

    // ------------------------------------------------------------------ update

    public function test_choosing_another_primary_photo_releases_the_previous_one(): void
    {
        $first = $this->makePhoto($this->artisan, 0, true);
        $second = $this->makePhoto($this->artisan, 1);

        $this->asUser($this->owner)->patchJson($this->url($this->artisan, $second), ['is_primary' => true])
            ->assertOk()
            ->assertJsonPath('data.is_primary', true);

        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
        $this->assertSame(1, Photo::where('artisan_id', $this->artisan->id)->where('is_primary', true)->count());
    }

    public function test_the_primary_photo_cannot_simply_be_unset_and_a_hidden_photo_cannot_be_primary(): void
    {
        $primary = $this->makePhoto($this->artisan, 0, true);
        $hidden = $this->makePhoto($this->artisan, 1, false, false);

        $this->asUser($this->owner)->patchJson($this->url($this->artisan, $primary), ['is_primary' => false])
            ->assertUnprocessable()->assertJsonValidationErrors(['is_primary']);

        $this->asUser($this->owner)->patchJson($this->url($this->artisan, $hidden), ['is_primary' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['is_primary']);

        $this->assertTrue($primary->fresh()->is_primary);
        $this->assertFalse($hidden->fresh()->is_primary);
    }

    public function test_the_position_can_be_changed_within_limits(): void
    {
        $photo = $this->makePhoto($this->artisan, 0, true);

        $this->asUser($this->owner)->patchJson($this->url($this->artisan, $photo), ['position' => 5])
            ->assertOk()->assertJsonPath('data.position', 5);
        $this->assertSame(5, $photo->fresh()->position);

        foreach ([-1, 101, 'abc'] as $invalid) {
            $this->asUser($this->owner)->patchJson($this->url($this->artisan, $photo), ['position' => $invalid])
                ->assertUnprocessable()->assertJsonValidationErrors(['position']);
        }

        $this->assertSame(5, $photo->fresh()->position);
    }

    public function test_only_the_staff_can_hide_a_photo_and_the_next_one_becomes_primary(): void
    {
        $owner = User::factory()->create();
        $artisan = $this->makeArtisan('validated', $owner);
        $primary = $this->makePhoto($artisan, 0, true);
        $next = $this->makePhoto($artisan, 1);

        $this->asUser($owner)->patchJson($this->url($artisan, $primary), ['is_active' => false])
            ->assertUnprocessable()->assertJsonValidationErrors(['is_active']);
        $this->assertTrue($primary->fresh()->is_active);

        $this->asUser(User::factory()->moderator()->create())->patchJson($this->url($artisan, $primary), ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.is_primary', false);

        $this->assertTrue($next->fresh()->is_primary);
        $this->assertSame(1, Photo::where('artisan_id', $artisan->id)->where('is_primary', true)->count());

        $public = $this->asGuest()->getJson($this->url($artisan))->json('data');
        $this->assertSame([$next->uuid], array_column($public, 'uuid'));
    }

    public function test_only_the_whitelisted_fields_are_applied(): void
    {
        $photo = $this->makePhoto($this->artisan, 0, true);
        $path = $photo->path;
        $uuid = $photo->uuid;

        $this->asUser($this->owner)->patchJson($this->url($this->artisan, $photo), [
            'path' => 'hacked/elsewhere.webp',
            'disk' => 'local',
            'artisan_id' => 999999,
            'uuid' => '00000000-0000-4000-8000-000000000000',
            'position' => 3,
        ])->assertOk();

        $fresh = $photo->fresh();

        $this->assertSame($path, $fresh->path);
        $this->assertSame($uuid, $fresh->uuid);
        $this->assertSame($this->artisan->id, $fresh->artisan_id);
        $this->assertSame(3, $fresh->position);
    }

    public function test_a_photo_of_another_profile_is_a_404_in_the_url(): void
    {
        $other = $this->makeArtisan('draft', User::factory()->create());
        $foreign = $this->makePhoto($other, 0, true);

        $this->asUser($this->owner)->patchJson($this->url($this->artisan, $foreign), ['position' => 4])->assertNotFound();
        $this->asUser($this->owner)->deleteJson($this->url($this->artisan, $foreign))->assertNotFound();

        $this->assertSame(0, $foreign->fresh()->position);
        $this->assertSame(1, Photo::count());
    }

    public function test_changing_and_deleting_photos_requires_the_right_to_edit_the_profile(): void
    {
        $validated = $this->makeArtisan('validated', User::factory()->create());
        $photo = $this->makePhoto($validated, 0, true);
        $stranger = User::factory()->create();

        $this->asGuest()->patchJson($this->url($validated, $photo), ['position' => 1])->assertUnauthorized();
        $this->asGuest()->deleteJson($this->url($validated, $photo))->assertUnauthorized();
        $this->asUser($stranger)->patchJson($this->url($validated, $photo), ['position' => 1])->assertForbidden();
        $this->asUser($stranger)->deleteJson($this->url($validated, $photo))->assertForbidden();

        $draftPhoto = $this->makePhoto($this->artisan, 0, true);
        $this->asUser($stranger)->deleteJson($this->url($this->artisan, $draftPhoto))->assertNotFound();

        $suspendedOwner = User::factory()->create();
        $suspended = $this->makeArtisan('suspended', $suspendedOwner);
        $suspendedPhoto = $this->makePhoto($suspended, 0, true);
        $this->asUser($suspendedOwner)->deleteJson($this->url($suspended, $suspendedPhoto))->assertForbidden();

        $this->assertSame(3, Photo::count());
        $this->assertSame(0, $photo->fresh()->position);
    }

    // ------------------------------------------------------------------ delete

    public function test_deleting_a_photo_removes_the_row_and_the_file(): void
    {
        $this->upload($this->owner, $this->artisan)->assertCreated();
        $this->upload($this->owner, $this->artisan)->assertCreated();

        $first = Photo::orderBy('position')->firstOrFail();
        $second = Photo::orderBy('position')->skip(1)->firstOrFail();
        $firstPath = $first->path;
        $secondPath = $second->path;

        $this->asUser($this->owner)->deleteJson($this->url($this->artisan, $first))->assertNoContent();

        $this->assertNull(Photo::where('uuid', $first->uuid)->first());
        Storage::disk($this->disk)->assertMissing($firstPath);
        Storage::disk($this->disk)->assertExists($secondPath);
    }

    public function test_deleting_the_primary_photo_promotes_the_next_active_one(): void
    {
        $primary = $this->makePhoto($this->artisan, 0, true);
        $hidden = $this->makePhoto($this->artisan, 1, false, false);
        $next = $this->makePhoto($this->artisan, 2);

        $this->asUser($this->owner)->deleteJson($this->url($this->artisan, $primary))->assertNoContent();

        $this->assertTrue($next->fresh()->is_primary);
        $this->assertFalse($hidden->fresh()->is_primary);
        $this->assertSame(1, Photo::where('artisan_id', $this->artisan->id)->where('is_primary', true)->count());
    }

    public function test_deleting_the_last_photo_leaves_an_empty_gallery_that_can_be_refilled(): void
    {
        $this->upload($this->owner, $this->artisan)->assertCreated();
        $photo = Photo::firstOrFail();

        $this->asUser($this->owner)->deleteJson($this->url($this->artisan, $photo))->assertNoContent();

        $this->assertSame(0, Photo::count());
        $this->assertSame([], Storage::disk($this->disk)->allFiles());

        $again = $this->upload($this->owner, $this->artisan)->assertCreated()->json('data');
        $this->assertTrue($again['is_primary']);
    }
}
