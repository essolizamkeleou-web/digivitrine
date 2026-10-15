<?php

namespace Tests\Feature\Api;

use App\Enums\ArtisanStatus;
use App\Models\Artisan;
use App\Models\Category;
use App\Models\City;
use App\Models\Neighborhood;
use App\Models\OpeningHour;
use App\Models\Photo;
use App\Models\Service;
use App\Models\Subcategory;
use App\Models\User;
use App\Policies\ArtisanPolicy;
use App\Support\ApiMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\Concerns\MakesApiRequests;
use Tests\TestCase;

class ArtisanApiTest extends TestCase
{
    use MakesApiRequests;
    use RefreshDatabase;

    private const LIST = '/api/v1/artisans';

    private const PUBLIC_KEYS = [
        'uuid', 'business_name', 'description', 'phone', 'whatsapp_phone', 'whatsapp_url', 'call_url', 'address',
        'latitude', 'longitude', 'neighborhood', 'categories', 'services', 'opening_hours', 'photos', 'created_at',
    ];

    private City $city;

    private Neighborhood $neighborhood;

    private Category $category;

    private Subcategory $subcategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = City::factory()->create(['slug' => 'lome', 'name' => 'Lome']);
        $this->neighborhood = Neighborhood::factory()->for($this->city)->create(['slug' => 'quartier-un', 'name' => 'Quartier Un']);
        $this->category = Category::factory()->create(['slug' => 'couture', 'name' => 'Couture']);
        $this->subcategory = Subcategory::factory()->for($this->category)->create(['slug' => 'hommes', 'name' => 'Hommes']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Atelier Kofi',
            'description' => 'Couture sur mesure.',
            'phone' => '90 12 34 56',
            'whatsapp_phone' => '+228 91 23 45 67',
            'address' => 'Rue des Artisans',
            'latitude' => 6.1319,
            'longitude' => 1.2228,
            'category' => 'couture',
            'subcategory' => 'hommes',
            'city' => 'lome',
            'neighborhood' => 'quartier-un',
        ], $overrides);
    }

    private function asUser(User $user): static
    {
        return $this->withApiToken($this->tokenFor($user));
    }

    /**
     * A profile in the fixture neighborhood and category, in the given factory state.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function makeArtisan(string $state = 'validated', ?User $owner = null, array $attributes = []): Artisan
    {
        $factory = Artisan::factory()->for($this->neighborhood)->{$state}();

        if ($owner !== null) {
            $factory = $factory->ownedBy($owner);
        }

        $artisan = $factory->create($attributes);
        $artisan->categories()->attach($this->category->id, ['subcategory_id' => $this->subcategory->id, 'is_primary' => true]);

        // Database defaults (the uuid) are not in memory after a factory create.
        return $artisan->refresh();
    }

    private function endpoint(Artisan $artisan, string $suffix = ''): string
    {
        return self::LIST.'/'.$artisan->uuid.$suffix;
    }

    // ------------------------------------------------------------------ creation

    public function test_an_artisan_creates_their_own_profile_as_a_draft(): void
    {
        $user = User::factory()->create();

        $response = $this->asUser($user)->postJson(self::LIST, $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.business_name', 'Atelier Kofi')
            ->assertJsonPath('data.phone', '+22890123456')
            ->assertJsonPath('data.whatsapp_phone', '+22891234567')
            ->assertJsonPath('data.neighborhood.slug', 'quartier-un')
            ->assertJsonPath('data.neighborhood.city.slug', 'lome')
            ->assertJsonPath('data.categories.0.slug', 'couture')
            ->assertJsonPath('data.categories.0.subcategory.slug', 'hommes')
            ->assertJsonPath('data.categories.0.is_primary', true);

        $artisan = Artisan::firstOrFail();

        $this->assertSame($user->id, $artisan->user_id);
        $this->assertSame($user->id, $artisan->created_by);
        $this->assertSame(ArtisanStatus::Draft, $artisan->status);
        $this->assertSame($artisan->uuid, $response->json('data.uuid'));
        $this->assertTrue(Str::isUuid($artisan->uuid));
        $this->assertSame('6.131900', $artisan->latitude);
        $this->assertSame($this->neighborhood->id, $artisan->neighborhood_id);
    }

    public function test_the_owner_response_has_exactly_the_documented_fields(): void
    {
        $response = $this->asUser(User::factory()->create())->postJson(self::LIST, $this->payload())->assertCreated();

        $this->assertEqualsCanonicalizing(
            array_merge(self::PUBLIC_KEYS, ['status', 'status_reason']),
            array_keys($response->json('data'))
        );
    }

    public function test_an_artisan_cannot_create_a_second_profile(): void
    {
        $user = User::factory()->create();
        $this->makeArtisan('draft', $user);

        $this->asUser($user)->postJson(self::LIST, $this->payload(['phone' => '99 99 99 99']))
            ->assertStatus(409)
            ->assertExactJson(['message' => ApiMessages::PROFILE_EXISTS]);

        $this->assertSame(1, Artisan::count());
    }

    public function test_a_field_agent_creates_profiles_without_owner(): void
    {
        $agent = User::factory()->fieldAgent()->create();

        $this->asUser($agent)->postJson(self::LIST, $this->payload())->assertCreated()->assertJsonPath('data.status', 'draft');
        $this->asUser($agent)->postJson(self::LIST, $this->payload(['business_name' => 'Autre atelier', 'phone' => '91 00 00 01']))->assertCreated();

        $this->assertSame(2, Artisan::count());

        foreach (Artisan::all() as $artisan) {
            $this->assertNull($artisan->user_id);
            $this->assertSame($agent->id, $artisan->created_by);
        }
    }

    public function test_staff_can_create_profiles_without_owner(): void
    {
        foreach (['admin' => '92 00 00 01', 'moderator' => '92 00 00 02'] as $state => $phone) {
            $staff = User::factory()->{$state}()->create();

            $this->asUser($staff)->postJson(self::LIST, $this->payload(['phone' => $phone]))->assertCreated();
        }

        $this->assertSame(2, Artisan::whereNull('user_id')->count());
    }

    public function test_creating_a_profile_requires_an_active_authenticated_account(): void
    {
        $this->asGuest()->postJson(self::LIST, $this->payload())->assertUnauthorized();

        $this->asUser(User::factory()->inactive()->create())->postJson(self::LIST, $this->payload())
            ->assertForbidden()
            ->assertExactJson(['message' => ApiMessages::ACCOUNT_DISABLED]);

        $this->assertSame(0, Artisan::count());
    }

    public function test_creation_validates_the_required_fields(): void
    {
        $this->asUser(User::factory()->create())->postJson(self::LIST, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['business_name', 'phone', 'category', 'city', 'neighborhood'])
            ->assertJsonPath('message', ApiMessages::INVALID_DATA);

        $this->assertSame(0, Artisan::count());
    }

    public function test_phone_numbers_are_validated_and_must_be_unique(): void
    {
        $user = User::factory()->create();

        foreach (['123', '+33612345678', '9012345', 'abcdefgh'] as $phone) {
            $this->asUser($user)->postJson(self::LIST, $this->payload(['phone' => $phone]))
                ->assertUnprocessable()->assertJsonValidationErrors(['phone']);
        }

        $this->asUser($user)->postJson(self::LIST, $this->payload(['whatsapp_phone' => '+33612345678']))
            ->assertUnprocessable()->assertJsonValidationErrors(['whatsapp_phone']);

        $this->makeArtisan('validated', null, ['phone' => '+22890123456']);

        $this->asUser($user)->postJson(self::LIST, $this->payload(['phone' => '90 12 34 56']))
            ->assertUnprocessable()->assertJsonValidationErrors(['phone']);

        $this->assertSame(1, Artisan::count());
    }

    public function test_a_deleted_profile_releases_its_phone_number(): void
    {
        $this->makeArtisan('validated', null, ['phone' => '+22890123456'])->delete();

        $this->asUser(User::factory()->create())->postJson(self::LIST, $this->payload())->assertCreated();
    }

    public function test_coordinates_are_validated(): void
    {
        $user = User::factory()->create();

        $cases = [
            [['latitude' => 6.13, 'longitude' => null], 'longitude'],
            [['latitude' => null, 'longitude' => 1.2], 'latitude'],
            [['latitude' => 91, 'longitude' => 1.2], 'latitude'],
            [['latitude' => 6.1, 'longitude' => 181], 'longitude'],
        ];

        foreach ($cases as [$override, $field]) {
            $this->asUser($user)->postJson(self::LIST, $this->payload($override))
                ->assertUnprocessable()->assertJsonValidationErrors([$field]);
        }

        $this->assertSame(0, Artisan::count());
    }

    public function test_catalogue_references_are_checked(): void
    {
        $menuiserie = Category::factory()->create(['slug' => 'menuiserie']);
        Subcategory::factory()->for($menuiserie)->create(['slug' => 'portes']);
        Category::factory()->create(['slug' => 'archive', 'is_active' => false]);
        $kara = City::factory()->create(['slug' => 'kara']);
        Neighborhood::factory()->for($kara)->create(['slug' => 'autre-quartier']);
        City::factory()->create(['slug' => 'fermee', 'is_active' => false]);

        $cases = [
            [['category' => 'inconnue'], 'category'],
            [['category' => 'archive'], 'category'],
            [['subcategory' => 'portes'], 'subcategory'],
            [['subcategory' => 'inconnue'], 'subcategory'],
            [['city' => 'inconnue'], 'city'],
            [['city' => 'fermee'], 'city'],
            [['neighborhood' => 'inconnu'], 'neighborhood'],
            [['neighborhood' => 'autre-quartier'], 'neighborhood'],
            [['country' => 'GH'], 'city'],
        ];

        $user = User::factory()->create();

        foreach ($cases as [$override, $field]) {
            $this->asUser($user)->postJson(self::LIST, $this->payload($override))
                ->assertUnprocessable()->assertJsonValidationErrors([$field]);
        }

        $this->assertSame(0, Artisan::count());
    }

    public function test_the_status_cannot_be_set_by_the_client(): void
    {
        $this->asUser(User::factory()->create())->postJson(self::LIST, $this->payload(['status' => 'validated']))
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $this->assertSame(0, Artisan::count());
    }

    public function test_ownership_and_validation_fields_are_ignored_on_creation(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->asUser($user)->postJson(self::LIST, $this->payload([
            'user_id' => $other->id,
            'created_by' => $other->id,
            'validated_by' => $other->id,
            'uuid' => '00000000-0000-4000-8000-000000000000',
            'id' => 999999,
            'status_reason' => 'injected',
        ]))->assertCreated();

        $artisan = Artisan::firstOrFail();

        $this->assertSame($user->id, $artisan->user_id);
        $this->assertSame($user->id, $artisan->created_by);
        $this->assertNull($artisan->validated_by);
        $this->assertNull($artisan->status_reason);
        $this->assertNotSame(999999, $artisan->id);
        $this->assertNotSame('00000000-0000-4000-8000-000000000000', $artisan->uuid);
        $this->assertSame(ArtisanStatus::Draft, $artisan->status);
    }

    // ------------------------------------------------------------------ show

    public function test_a_validated_profile_is_public_and_hides_private_fields(): void
    {
        $artisan = $this->makeArtisan('validated');

        $response = $this->asGuest()->getJson($this->endpoint($artisan))->assertOk();

        $this->assertEqualsCanonicalizing(self::PUBLIC_KEYS, array_keys($response->json('data')));
        $response->assertJsonPath('data.uuid', $artisan->uuid)->assertJsonPath('data.categories.0.slug', 'couture');

        foreach (['id', 'user_id', 'created_by', 'validated_by', 'deleted_at', 'status', 'status_reason'] as $key) {
            $response->assertJsonMissingPath("data.{$key}");
        }
    }

    public function test_the_public_profile_contains_active_services_photos_and_hours(): void
    {
        $artisan = $this->makeArtisan('validated');
        Service::factory()->for($artisan)->create(['name' => 'Retouche', 'position' => 20]);
        Service::factory()->for($artisan)->create(['name' => 'Robe', 'position' => 10]);
        Service::factory()->for($artisan)->inactive()->create(['name' => 'Cache']);
        Photo::factory()->for($artisan)->primary()->create(['position' => 0]);
        Photo::factory()->for($artisan)->create(['position' => 1]);
        Photo::factory()->for($artisan)->hidden()->create(['position' => 2]);
        OpeningHour::factory()->for($artisan)->forDay(1)->between('14:00', '18:00')->create();
        OpeningHour::factory()->for($artisan)->forDay(1)->between('08:00', '12:00')->create();
        OpeningHour::factory()->for($artisan)->forDay(7)->closed()->create();

        $data = $this->asGuest()->getJson($this->endpoint($artisan))->assertOk()->json('data');

        $this->assertSame(['Robe', 'Retouche'], array_column($data['services'], 'name'));
        $this->assertEqualsCanonicalizing(['uuid', 'name', 'description'], array_keys($data['services'][0]));

        $this->assertCount(2, $data['photos']);
        $this->assertTrue($data['photos'][0]['is_primary']);
        $this->assertEqualsCanonicalizing(['uuid', 'url', 'width', 'height', 'is_primary', 'position'], array_keys($data['photos'][0]));
        $this->assertStringContainsString('/storage/', $data['photos'][0]['url']);

        $this->assertCount(3, $data['opening_hours']);
        $this->assertSame('08:00', $data['opening_hours'][0]['opens_at']);
        $this->assertSame('14:00', $data['opening_hours'][1]['opens_at']);
        $this->assertTrue($data['opening_hours'][2]['is_closed']);
        $this->assertNull($data['opening_hours'][2]['opens_at']);
    }

    public function test_whatsapp_and_call_links_are_built_from_the_numbers(): void
    {
        $with = $this->makeArtisan('validated', null, ['phone' => '+22890123456', 'whatsapp_phone' => '+22891234567']);
        $without = $this->makeArtisan('validated', null, ['whatsapp_phone' => null]);

        $data = $this->asGuest()->getJson($this->endpoint($with))->assertOk()->json('data');

        $this->assertStringStartsWith('https://wa.me/22891234567?text=', $data['whatsapp_url']);
        $this->assertStringNotContainsString('+', $data['whatsapp_url']);
        $this->assertSame('tel:+22890123456', $data['call_url']);

        $this->assertNull($this->asGuest()->getJson($this->endpoint($without))->json('data.whatsapp_url'));
    }

    public function test_a_profile_that_is_not_validated_is_a_404_for_strangers(): void
    {
        $owner = User::factory()->create();
        $agent = User::factory()->fieldAgent()->create();
        $draft = $this->makeArtisan('draft', $owner, ['created_by' => $agent->id]);

        $this->asGuest()->getJson($this->endpoint($draft))
            ->assertNotFound()
            ->assertExactJson(['message' => ApiMessages::NOT_FOUND]);

        foreach ([User::factory()->create(), User::factory()->fieldAgent()->create()] as $stranger) {
            $this->asUser($stranger)->getJson($this->endpoint($draft))->assertNotFound();
        }

        foreach ([$owner, $agent, User::factory()->admin()->create(), User::factory()->moderator()->create()] as $allowed) {
            $this->asUser($allowed)->getJson($this->endpoint($draft))->assertOk();
        }
    }

    public function test_the_owner_sees_the_status_of_their_profile(): void
    {
        $owner = User::factory()->create();
        $artisan = $this->makeArtisan('validated', $owner);

        $this->asUser($owner)->getJson($this->endpoint($artisan))
            ->assertOk()
            ->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.status_reason', null);

        $this->asGuest()->getJson($this->endpoint($artisan))->assertOk()->assertJsonMissingPath('data.status');
    }

    public function test_suspended_rejected_deleted_and_malformed_profiles_are_not_public(): void
    {
        $owner = User::factory()->create();
        $suspended = $this->makeArtisan('suspended', $owner);
        $rejected = $this->makeArtisan('rejected', User::factory()->create());
        $gone = $this->makeArtisan('validated');
        $gone->delete();

        $this->asGuest()->getJson($this->endpoint($suspended))->assertNotFound();
        $this->asGuest()->getJson($this->endpoint($rejected))->assertNotFound();
        $this->asGuest()->getJson($this->endpoint($gone))->assertNotFound();
        $this->asGuest()->getJson(self::LIST.'/not-a-uuid')->assertNotFound();

        $this->asUser($owner)->getJson($this->endpoint($suspended))
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.status_reason', fn ($reason) => is_string($reason) && $reason !== '');
    }

    public function test_an_invalid_or_deactivated_token_is_treated_as_a_guest(): void
    {
        $draft = $this->makeArtisan('draft', User::factory()->create());
        $validated = $this->makeArtisan('validated');

        $this->withApiToken('1|not-a-real-token')->getJson($this->endpoint($validated))->assertOk();
        $this->withApiToken('1|not-a-real-token')->getJson($this->endpoint($draft))->assertNotFound();

        $inactive = User::factory()->inactive()->create();
        $inactiveDraft = $this->makeArtisan('draft', $inactive);

        $this->asUser($inactive)->getJson($this->endpoint($inactiveDraft))->assertNotFound();
    }

    // ------------------------------------------------------------------ update

    public function test_an_owner_can_replace_their_profile_with_put(): void
    {
        $owner = User::factory()->create();
        $artisan = $this->makeArtisan('draft', $owner);

        $this->asUser($owner)->putJson($this->endpoint($artisan), $this->payload([
            'business_name' => 'Nouveau nom',
            'description' => 'Nouvelle description',
            'phone' => '92 11 22 33',
        ]))
            ->assertOk()
            ->assertJsonPath('data.business_name', 'Nouveau nom')
            ->assertJsonPath('data.phone', '+22892112233')
            ->assertJsonPath('data.status', 'draft');

        $this->assertSame('Nouvelle description', $artisan->fresh()->description);
    }

    public function test_an_owner_can_patch_a_single_field(): void
    {
        $owner = User::factory()->create();
        $artisan = $this->makeArtisan('draft', $owner, ['business_name' => 'Nom initial']);

        $this->asUser($owner)->patchJson($this->endpoint($artisan), ['description' => 'Seule la description change'])
            ->assertOk()
            ->assertJsonPath('data.business_name', 'Nom initial')
            ->assertJsonPath('data.description', 'Seule la description change')
            ->assertJsonPath('data.categories.0.slug', 'couture');
    }

    public function test_nobody_else_can_modify_a_profile(): void
    {
        $draft = $this->makeArtisan('draft', User::factory()->create(), ['description' => 'Original']);
        $validated = $this->makeArtisan('validated', User::factory()->create(), ['description' => 'Original']);
        $stranger = User::factory()->create();

        $this->asUser($stranger)->patchJson($this->endpoint($draft), ['description' => 'Pirate'])->assertNotFound();
        $this->asUser($stranger)->patchJson($this->endpoint($validated), ['description' => 'Pirate'])
            ->assertForbidden()
            ->assertExactJson(['message' => ApiMessages::FORBIDDEN]);
        $this->asUser($stranger)->putJson($this->endpoint($validated), $this->payload())->assertForbidden();

        $this->assertSame('Original', $draft->fresh()->description);
        $this->assertSame('Original', $validated->fresh()->description);
    }

    public function test_a_stranger_gets_no_validation_feedback_on_a_profile_they_cannot_see(): void
    {
        $draft = $this->makeArtisan('draft', User::factory()->create());

        $this->asUser(User::factory()->create())->putJson($this->endpoint($draft), [])->assertNotFound();
        $this->asUser(User::factory()->create())->patchJson($this->endpoint($draft), ['phone' => 'invalide'])->assertNotFound();
    }

    public function test_updating_and_submitting_require_authentication(): void
    {
        $artisan = $this->makeArtisan('draft', User::factory()->create());

        $this->asGuest()->patchJson($this->endpoint($artisan), ['description' => 'x'])->assertUnauthorized();
        $this->asGuest()->putJson($this->endpoint($artisan), $this->payload())->assertUnauthorized();
        $this->asGuest()->postJson($this->endpoint($artisan, '/submit'))->assertUnauthorized();
    }

    public function test_an_artisan_cannot_self_validate_through_an_update(): void
    {
        $owner = User::factory()->create();
        $artisan = $this->makeArtisan('draft', $owner);

        foreach (['validated', 'suspended', 'pending'] as $status) {
            $this->asUser($owner)->patchJson($this->endpoint($artisan), ['status' => $status])
                ->assertUnprocessable()->assertJsonValidationErrors(['status']);
            $this->asUser($owner)->putJson($this->endpoint($artisan), $this->payload(['status' => $status]))
                ->assertUnprocessable()->assertJsonValidationErrors(['status']);
        }

        $fresh = $artisan->fresh();

        $this->assertSame(ArtisanStatus::Draft, $fresh->status);
        $this->assertNull($fresh->validated_by);
        $this->assertNull($fresh->validated_at);
    }

    public function test_editing_sensitive_fields_of_a_validated_profile_requires_a_new_validation(): void
    {
        $owner = User::factory()->create();
        $artisan = $this->makeArtisan('validated', $owner);

        $this->asUser($owner)->patchJson($this->endpoint($artisan), ['description' => 'Autre description'])
            ->assertOk()->assertJsonPath('data.status', 'validated');

        $this->asUser($owner)->patchJson($this->endpoint($artisan), ['business_name' => 'Nom modifie'])
            ->assertOk()->assertJsonPath('data.status', 'pending');

        $fresh = $artisan->fresh();
        $this->assertSame(ArtisanStatus::Pending, $fresh->status);
        $this->assertNull($fresh->validated_by);
        $this->assertNull($fresh->validated_at);

        $categoryOwner = User::factory()->create();
        $other = $this->makeArtisan('validated', $categoryOwner);
        Category::factory()->create(['slug' => 'menuiserie']);

        $this->asUser($categoryOwner)->patchJson($this->endpoint($other), ['category' => 'menuiserie'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.categories.0.slug', 'menuiserie')
            ->assertJsonPath('data.categories.0.subcategory', null);

        $categories = $other->fresh()->categories;
        $this->assertCount(1, $categories);
        $this->assertSame('menuiserie', $categories->first()->slug);
        $this->assertTrue((bool) $categories->first()->pivot->is_primary);
    }

    public function test_staff_edits_keep_a_validated_profile_validated(): void
    {
        $artisan = $this->makeArtisan('validated', User::factory()->create());
        $admin = User::factory()->admin()->create();

        $this->asUser($admin)->patchJson($this->endpoint($artisan), ['business_name' => 'Corrige par le personnel'])
            ->assertOk()
            ->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.business_name', 'Corrige par le personnel');

        $this->assertNotNull($artisan->fresh()->validated_by);
    }

    public function test_a_suspended_profile_can_only_be_edited_by_staff(): void
    {
        $owner = User::factory()->create();
        $artisan = $this->makeArtisan('suspended', $owner);

        $this->asUser($owner)->patchJson($this->endpoint($artisan), ['description' => 'x'])->assertForbidden();

        $this->asUser(User::factory()->admin()->create())->patchJson($this->endpoint($artisan), ['description' => 'Corrige'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');
    }

    public function test_a_field_agent_manages_the_profiles_they_created_until_they_are_claimed(): void
    {
        $agent = User::factory()->fieldAgent()->create();
        $otherAgent = User::factory()->fieldAgent()->create();

        $unclaimed = $this->makeArtisan('draft', null, ['created_by' => $agent->id]);

        $this->asUser($agent)->patchJson($this->endpoint($unclaimed), ['description' => 'Completee sur le terrain'])->assertOk();
        $this->asUser($otherAgent)->patchJson($this->endpoint($unclaimed), ['description' => 'x'])->assertNotFound();

        $claimed = $this->makeArtisan('draft', User::factory()->create(), ['created_by' => $agent->id]);

        $this->asUser($agent)->patchJson($this->endpoint($claimed), ['description' => 'x'])->assertForbidden();
    }

    public function test_updating_a_phone_ignores_the_profile_itself_but_not_the_others(): void
    {
        $owner = User::factory()->create();
        $mine = $this->makeArtisan('draft', $owner, ['phone' => '+22890123456']);
        $this->makeArtisan('validated', null, ['phone' => '+22891234567']);

        $this->asUser($owner)->putJson($this->endpoint($mine), $this->payload(['phone' => '90 12 34 56']))->assertOk();

        $this->asUser($owner)->patchJson($this->endpoint($mine), ['phone' => '91 23 45 67'])
            ->assertUnprocessable()->assertJsonValidationErrors(['phone']);
    }

    public function test_partial_updates_require_paired_fields(): void
    {
        $owner = User::factory()->create();
        $artisan = $this->makeArtisan('draft', $owner);
        $url = $this->endpoint($artisan);

        $this->asUser($owner)->patchJson($url, ['city' => 'lome'])->assertUnprocessable()->assertJsonValidationErrors(['neighborhood']);
        $this->asUser($owner)->patchJson($url, ['neighborhood' => 'quartier-un'])->assertUnprocessable()->assertJsonValidationErrors(['city']);
        $this->asUser($owner)->patchJson($url, ['subcategory' => 'hommes'])->assertUnprocessable()->assertJsonValidationErrors(['category']);
        $this->asUser($owner)->patchJson($url, ['category' => 'couture', 'subcategory' => 'inconnue'])
            ->assertUnprocessable()->assertJsonValidationErrors(['subcategory']);
    }

    public function test_coordinates_can_be_cleared(): void
    {
        $owner = User::factory()->create();
        $artisan = $this->makeArtisan('draft', $owner, ['latitude' => 6.1, 'longitude' => 1.2]);

        $this->asUser($owner)->patchJson($this->endpoint($artisan), ['latitude' => null, 'longitude' => null])
            ->assertOk()
            ->assertJsonPath('data.latitude', null)
            ->assertJsonPath('data.longitude', null);

        $fresh = $artisan->fresh();
        $this->assertNull($fresh->latitude);
        $this->assertNull($fresh->longitude);
    }

    // ------------------------------------------------------------------ submit

    public function test_submitting_moves_a_draft_or_rejected_profile_to_pending(): void
    {
        $draftOwner = User::factory()->create();
        $draft = $this->makeArtisan('draft', $draftOwner);

        $this->asUser($draftOwner)->postJson($this->endpoint($draft, '/submit'))
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $rejectedOwner = User::factory()->create();
        $rejected = $this->makeArtisan('rejected', $rejectedOwner);

        $this->asUser($rejectedOwner)->postJson($this->endpoint($rejected, '/submit'))
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.status_reason', null);

        $this->assertNull($rejected->fresh()->status_reason);
    }

    public function test_other_states_cannot_be_submitted(): void
    {
        foreach (['pending', 'validated', 'suspended'] as $state) {
            $owner = User::factory()->create();
            $artisan = $this->makeArtisan($state, $owner);

            $this->asUser($owner)->postJson($this->endpoint($artisan, '/submit'))
                ->assertStatus(409)
                ->assertExactJson(['message' => ApiMessages::STATUS_CONFLICT]);

            $this->assertSame(ArtisanStatus::from($state), $artisan->fresh()->status);
        }
    }

    public function test_submitting_is_restricted_to_managers(): void
    {
        $draft = $this->makeArtisan('draft', User::factory()->create());
        $validated = $this->makeArtisan('validated', User::factory()->create());
        $stranger = User::factory()->create();

        $this->asUser($stranger)->postJson($this->endpoint($draft, '/submit'))->assertNotFound();
        $this->asUser($stranger)->postJson($this->endpoint($validated, '/submit'))->assertForbidden();

        $agent = User::factory()->fieldAgent()->create();
        $unclaimed = $this->makeArtisan('draft', null, ['created_by' => $agent->id]);

        $this->asUser($agent)->postJson($this->endpoint($unclaimed, '/submit'))
            ->assertOk()->assertJsonPath('data.status', 'pending');
    }

    // ------------------------------------------------------------------ list

    public function test_the_public_list_shows_only_validated_profiles(): void
    {
        $this->makeArtisan('validated');
        $this->makeArtisan('validated');
        foreach (['draft', 'pending', 'rejected', 'suspended'] as $state) {
            $this->makeArtisan($state);
        }

        $this->asGuest()->getJson(self::LIST)
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta' => ['current_page', 'per_page', 'total']])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(2, 'data');
    }

    public function test_the_list_is_paginated_with_a_maximum_page_size(): void
    {
        Artisan::factory()->count(25)->validated()->for($this->neighborhood)->create();

        $first = $this->asGuest()->getJson(self::LIST)->assertOk();
        $this->assertCount(20, $first->json('data'));
        $first->assertJsonPath('meta.total', 25)->assertJsonPath('meta.per_page', 20);

        $this->assertCount(5, $this->asGuest()->getJson(self::LIST.'?page=2')->json('data'));
        $this->assertCount(25, $this->asGuest()->getJson(self::LIST.'?per_page=50')->json('data'));
    }

    public function test_the_list_can_be_filtered(): void
    {
        $a = $this->makeArtisan('validated');

        $kara = City::factory()->create(['slug' => 'kara']);
        $otherNeighborhood = Neighborhood::factory()->for($kara)->create(['slug' => 'autre-quartier']);
        $menuiserie = Category::factory()->create(['slug' => 'menuiserie']);
        $b = Artisan::factory()->for($otherNeighborhood)->validated()->create()->refresh();
        $b->categories()->attach($menuiserie->id, ['is_primary' => true]);

        $uuids = fn (string $query): array => $this->asGuest()->getJson(self::LIST.$query)->assertOk()->json('data.*.uuid');

        $this->assertEqualsCanonicalizing([$a->uuid, $b->uuid], $uuids(''));
        $this->assertSame([$b->uuid], $uuids('?category=menuiserie'));
        $this->assertSame([$a->uuid], $uuids('?subcategory=hommes'));
        $this->assertSame([$b->uuid], $uuids('?city=kara'));
        $this->assertSame([$a->uuid], $uuids('?neighborhood=quartier-un'));
        $this->assertSame([], $uuids('?city=lome&category=menuiserie'));
        $this->assertSame([], $uuids('?city=lome&country=GH'));
        $this->assertSame([$a->uuid], $uuids('?city=LOME&category=COUTURE'));
    }

    public function test_list_items_are_summaries_without_contact_details(): void
    {
        $this->makeArtisan('validated');
        $withoutCategory = Artisan::factory()->for($this->neighborhood)->validated()->create(['business_name' => 'Zzz Sans categorie'])->refresh();

        $items = $this->asGuest()->getJson(self::LIST)->assertOk()->json('data');

        $this->assertCount(2, $items);

        foreach ($items as $item) {
            $this->assertEqualsCanonicalizing(
                ['uuid', 'business_name', 'description', 'neighborhood', 'category', 'subcategory', 'primary_photo'],
                array_keys($item)
            );
        }

        $this->assertEqualsCanonicalizing(['slug', 'name'], array_keys($items[0]['category']));
        $this->assertSame('quartier-un', $items[0]['neighborhood']['slug']);
        $this->assertSame('lome', $items[0]['neighborhood']['city']['slug']);
        $this->assertNull($items[1]['category']);
        $this->assertSame($withoutCategory->uuid, $items[1]['uuid']);
    }

    public function test_the_list_is_sorted_by_name_and_truncates_long_descriptions(): void
    {
        $this->makeArtisan('validated', null, ['business_name' => 'Zebre atelier', 'description' => str_repeat('a', 300)]);
        $this->makeArtisan('validated', null, ['business_name' => 'Alpha atelier', 'description' => 'Courte']);
        Photo::factory()->for(Artisan::where('business_name', 'Alpha atelier')->firstOrFail())->primary()->create();

        $items = $this->asGuest()->getJson(self::LIST)->json('data');

        $this->assertSame(['Alpha atelier', 'Zebre atelier'], array_column($items, 'business_name'));
        $this->assertSame('Courte', $items[0]['description']);
        $this->assertLessThanOrEqual(163, mb_strlen($items[1]['description']));
        $this->assertStringEndsWith('...', $items[1]['description']);
        $this->assertEqualsCanonicalizing(['uuid', 'url', 'width', 'height', 'is_primary', 'position'], array_keys($items[0]['primary_photo']));
        $this->assertNull($items[1]['primary_photo']);
    }

    public function test_list_parameters_are_validated(): void
    {
        foreach (['per_page=0', 'per_page=51', 'per_page=abc', 'page=0', 'country=XYZ', 'category=Bad%20Slug'] as $query) {
            $this->asGuest()->getJson(self::LIST.'?'.$query)
                ->assertUnprocessable()
                ->assertJsonPath('message', ApiMessages::INVALID_DATA);
        }
    }

    public function test_the_artisan_policy_is_registered(): void
    {
        $this->assertInstanceOf(ArtisanPolicy::class, Gate::getPolicyFor(Artisan::class));
    }
}
