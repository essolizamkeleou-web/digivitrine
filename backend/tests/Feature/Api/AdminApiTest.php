<?php

namespace Tests\Feature\Api;

use App\Enums\ArtisanStatus;
use App\Enums\ReportStatus;
use App\Models\Artisan;
use App\Models\Category;
use App\Models\City;
use App\Models\Neighborhood;
use App\Models\Photo;
use App\Models\Report;
use App\Models\User;
use App\Support\ApiMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\MakesApiRequests;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use MakesApiRequests;
    use RefreshDatabase;

    private const STATES = ['draft', 'pending', 'validated', 'rejected', 'suspended'];

    private Neighborhood $neighborhood;

    private Category $category;

    private User $admin;

    private User $moderator;

    protected function setUp(): void
    {
        parent::setUp();

        $city = City::factory()->create(['slug' => 'lome', 'name' => 'Lome']);
        $this->neighborhood = Neighborhood::factory()->for($city)->create(['slug' => 'tokoin', 'name' => 'Tokoin']);
        $this->category = Category::factory()->create(['slug' => 'couture', 'name' => 'Couture']);
        $this->admin = User::factory()->admin()->create()->refresh();
        $this->moderator = User::factory()->moderator()->create()->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeArtisan(string $state = 'pending', ?User $owner = null, array $attributes = [], ?Neighborhood $neighborhood = null, ?Category $category = null): Artisan
    {
        $factory = Artisan::factory()->for($neighborhood ?? $this->neighborhood)->{$state}();

        if ($owner !== null) {
            $factory = $factory->ownedBy($owner);
        }

        $artisan = $factory->create($attributes)->refresh();
        $artisan->categories()->attach(($category ?? $this->category)->id, ['is_primary' => true]);

        return $artisan;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeReport(Artisan $artisan, array $attributes = []): Report
    {
        return Report::factory()->for($artisan)->create($attributes)->refresh();
    }

    private function asUser(User $user): static
    {
        return $this->withApiToken($this->tokenFor($user));
    }

    private function admin(string $path = ''): string
    {
        return '/api/v1/admin/'.ltrim($path, '/');
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function endpoints(Artisan $artisan, Report $report): array
    {
        return [
            ['GET', 'artisans'],
            ['GET', "artisans/{$artisan->uuid}"],
            ['PATCH', "artisans/{$artisan->uuid}/validate"],
            ['PATCH', "artisans/{$artisan->uuid}/reject"],
            ['PATCH', "artisans/{$artisan->uuid}/suspend"],
            ['GET', 'reports'],
            ['PATCH', "reports/{$report->uuid}"],
        ];
    }

    // ------------------------------------------------------------------ access

    public function test_every_admin_endpoint_requires_authentication(): void
    {
        $artisan = $this->makeArtisan('pending');
        $report = $this->makeReport($artisan);

        foreach ($this->endpoints($artisan, $report) as [$method, $path]) {
            $this->asGuest()->json($method, $this->admin($path), ['reason' => 'Motif de test', 'status' => 'resolved'])
                ->assertUnauthorized()
                ->assertExactJson(['message' => ApiMessages::UNAUTHENTICATED]);
        }

        $this->assertSame(ArtisanStatus::Pending, $artisan->fresh()->status);
    }

    public function test_artisans_and_field_agents_are_forbidden_everywhere(): void
    {
        $artisan = $this->makeArtisan('pending');
        $report = $this->makeReport($artisan);

        foreach ([User::factory()->create(), User::factory()->fieldAgent()->create()] as $user) {
            foreach ($this->endpoints($artisan, $report) as [$method, $path]) {
                $this->asUser($user)->json($method, $this->admin($path), ['reason' => 'Motif de test', 'status' => 'resolved'])
                    ->assertForbidden()
                    ->assertExactJson(['message' => ApiMessages::FORBIDDEN]);
            }
        }

        $this->assertSame(ArtisanStatus::Pending, $artisan->fresh()->status);
        $this->assertSame(ReportStatus::Open, $report->fresh()->status);
    }

    public function test_the_owner_of_a_profile_cannot_validate_it_through_the_admin_api(): void
    {
        $owner = User::factory()->create();
        $artisan = $this->makeArtisan('pending', $owner);

        $this->asUser($owner)->patchJson($this->admin("artisans/{$artisan->uuid}/validate"))
            ->assertForbidden()
            ->assertExactJson(['message' => ApiMessages::FORBIDDEN]);

        $this->assertSame(ArtisanStatus::Pending, $artisan->fresh()->status);
    }

    public function test_a_deactivated_staff_account_is_refused(): void
    {
        $inactive = User::factory()->admin()->inactive()->create();

        $this->asUser($inactive)->getJson($this->admin('artisans'))
            ->assertForbidden()
            ->assertExactJson(['message' => ApiMessages::ACCOUNT_DISABLED]);
    }

    public function test_admins_and_moderators_can_read_the_lists(): void
    {
        foreach ([$this->admin, $this->moderator] as $staff) {
            $this->asUser($staff)->getJson($this->admin('artisans'))->assertOk();
            $this->asUser($staff)->getJson($this->admin('reports'))->assertOk();
        }
    }

    // ------------------------------------------------------------------ artisan list and detail

    public function test_staff_see_every_status_but_not_deleted_profiles(): void
    {
        foreach (self::STATES as $state) {
            $this->makeArtisan($state);
        }

        $this->makeArtisan('pending')->delete();

        $response = $this->asUser($this->admin)->getJson($this->admin('artisans'))
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta' => ['current_page', 'per_page', 'total']])
            ->assertJsonPath('meta.total', 5);

        $this->assertEqualsCanonicalizing(self::STATES, array_column($response->json('data'), 'status'));
    }

    public function test_list_rows_expose_the_documented_fields_only(): void
    {
        $owner = User::factory()->create();
        $this->makeArtisan('rejected', $owner, ['status_reason' => 'Photos manquantes']);

        $row = $this->asUser($this->admin)->getJson($this->admin('artisans'))->assertOk()->json('data.0');

        $this->assertEqualsCanonicalizing(
            ['uuid', 'business_name', 'phone', 'status', 'status_reason', 'neighborhood', 'category', 'has_owner', 'created_at', 'validated_at'],
            array_keys($row)
        );
        $this->assertSame('Photos manquantes', $row['status_reason']);
        $this->assertTrue($row['has_owner']);
        $this->assertSame('couture', $row['category']['slug']);
        $this->assertSame('tokoin', $row['neighborhood']['slug']);
        $this->assertSame('lome', $row['neighborhood']['city']['slug']);
    }

    public function test_the_list_can_be_filtered(): void
    {
        $pending = $this->makeArtisan('pending', null, ['business_name' => 'Atelier Kofi']);
        $validated = $this->makeArtisan('validated', null, ['business_name' => 'Garage Mensah']);

        $kara = City::factory()->create(['slug' => 'kara']);
        $otherNeighborhood = Neighborhood::factory()->for($kara)->create(['slug' => 'autre-quartier']);
        $menuiserie = Category::factory()->create(['slug' => 'menuiserie']);
        $other = $this->makeArtisan('draft', null, ['business_name' => 'Menuiserie Ouest'], $otherNeighborhood, $menuiserie);

        $uuids = fn (string $query): array => $this->asUser($this->admin)
            ->getJson($this->admin('artisans').$query)->assertOk()->json('data.*.uuid');

        $this->assertEqualsCanonicalizing([$pending->uuid, $validated->uuid, $other->uuid], $uuids(''));
        $this->assertSame([$pending->uuid], $uuids('?status=pending'));
        $this->assertSame([$other->uuid], $uuids('?status=draft'));
        $this->assertSame([$other->uuid], $uuids('?category=menuiserie'));
        $this->assertSame([$other->uuid], $uuids('?city=kara'));
        $this->assertSame([$other->uuid], $uuids('?neighborhood=autre-quartier'));
        $this->assertEqualsCanonicalizing([$pending->uuid, $validated->uuid], $uuids('?city=lome'));
        $this->assertSame([$pending->uuid], $uuids('?q=KOFI'));
        $this->assertSame([$validated->uuid], $uuids('?q=garage'));
        $this->assertSame([], $uuids('?q=kofi&status=validated'));
        $this->assertSame([], $uuids('?q=%27%3B+DROP+TABLE+artisans%3B--'));
    }

    public function test_the_pending_queue_lists_the_oldest_profiles_first(): void
    {
        $recent = $this->makeArtisan('pending', null, ['business_name' => 'Recent', 'created_at' => now()->subDay()]);
        $oldest = $this->makeArtisan('pending', null, ['business_name' => 'Ancien', 'created_at' => now()->subDays(3)]);
        $middle = $this->makeArtisan('pending', null, ['business_name' => 'Milieu', 'created_at' => now()->subDays(2)]);

        $names = fn (string $query): array => $this->asUser($this->admin)
            ->getJson($this->admin('artisans').$query)->assertOk()->json('data.*.business_name');

        $this->assertSame(['Ancien', 'Milieu', 'Recent'], $names('?status=pending'));
        $this->assertSame(['Recent', 'Milieu', 'Ancien'], $names(''));
        $this->assertSame([$recent->uuid, $oldest->uuid, $middle->uuid], [$recent->uuid, $oldest->uuid, $middle->uuid]);
    }

    public function test_the_list_is_paginated_and_its_parameters_are_validated(): void
    {
        Artisan::factory()->count(25)->pending()->for($this->neighborhood)->create();

        $this->asUser($this->admin)->getJson($this->admin('artisans'))
            ->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 25);
        $this->asUser($this->admin)->getJson($this->admin('artisans').'?page=2')->assertJsonCount(5, 'data');
        $this->asUser($this->admin)->getJson($this->admin('artisans').'?per_page=50')->assertJsonCount(25, 'data');

        foreach (['per_page=0', 'per_page=51', 'page=0', 'status=archived', 'country=XYZ', 'category=Bad%20Slug', 'q[]=a'] as $query) {
            $this->asUser($this->admin)->getJson($this->admin('artisans').'?'.$query)
                ->assertUnprocessable()
                ->assertJsonPath('message', ApiMessages::INVALID_DATA);
        }
    }

    public function test_the_detail_shows_private_data_people_and_hidden_photos(): void
    {
        $owner = User::factory()->create(['name' => 'Kofi Mensah'])->refresh();
        $artisan = $this->makeArtisan('validated', $owner);
        Photo::factory()->for($artisan)->primary()->create(['position' => 0]);
        Photo::factory()->for($artisan)->hidden()->create(['position' => 1]);

        $data = $this->asUser($this->moderator)->getJson($this->admin("artisans/{$artisan->uuid}"))->assertOk()->json('data');

        $publicKeys = ['uuid', 'business_name', 'description', 'phone', 'whatsapp_phone', 'whatsapp_url', 'call_url', 'address',
            'latitude', 'longitude', 'neighborhood', 'categories', 'services', 'opening_hours', 'photos', 'created_at'];

        $this->assertEqualsCanonicalizing(
            array_merge($publicKeys, ['status', 'status_reason', 'owner', 'created_by', 'validated_by', 'validated_at', 'updated_at']),
            array_keys($data)
        );
        $this->assertSame('validated', $data['status']);
        $this->assertSame($owner->uuid, $data['owner']['uuid']);
        $this->assertSame('Kofi Mensah', $data['owner']['name']);
        $this->assertEqualsCanonicalizing(['uuid', 'name', 'role'], array_keys($data['owner']));
        $this->assertSame('moderator', $data['validated_by']['role']);
        $this->assertNotNull($data['validated_at']);
        $this->assertSame('field_agent', $data['created_by']['role']);

        $this->assertCount(2, $data['photos']);
        $this->assertContains(false, array_column($data['photos'], 'is_active'));

        foreach (['id', 'user_id', 'validated_by_id', 'deleted_at'] as $key) {
            $this->assertArrayNotHasKey($key, $data);
        }
    }

    public function test_the_detail_works_for_a_draft_without_owner_and_is_404_for_unknown_or_deleted_profiles(): void
    {
        $draft = $this->makeArtisan('draft');

        $data = $this->asUser($this->admin)->getJson($this->admin("artisans/{$draft->uuid}"))->assertOk()->json('data');
        $this->assertNull($data['owner']);
        $this->assertNull($data['validated_by']);
        $this->assertNull($data['validated_at']);
        $this->assertSame('draft', $data['status']);

        $gone = $this->makeArtisan('pending');
        $gone->delete();

        foreach (['00000000-0000-4000-8000-000000000000', $gone->uuid] as $uuid) {
            $this->asUser($this->admin)->getJson($this->admin("artisans/{$uuid}"))
                ->assertNotFound()
                ->assertExactJson(['message' => ApiMessages::NOT_FOUND]);
        }

        $this->asUser($this->admin)->getJson($this->admin('artisans/not-a-uuid'))->assertNotFound();
    }

    // ------------------------------------------------------------------ validate

    public function test_a_pending_profile_is_validated_with_the_validator_recorded(): void
    {
        $artisan = $this->makeArtisan('pending');

        $this->asUser($this->admin)->patchJson($this->admin("artisans/{$artisan->uuid}/validate"))
            ->assertOk()
            ->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.status_reason', null)
            ->assertJsonPath('data.validated_by.uuid', $this->admin->uuid);

        $fresh = $artisan->fresh();

        $this->assertSame(ArtisanStatus::Validated, $fresh->status);
        $this->assertSame($this->admin->id, $fresh->validated_by);
        $this->assertTrue($fresh->validated_at->isToday());
        $this->assertNull($fresh->status_reason);
    }

    public function test_a_validated_profile_becomes_public(): void
    {
        $artisan = $this->makeArtisan('pending');

        $this->asGuest()->getJson('/api/v1/artisans')->assertOk()->assertJsonPath('meta.total', 0);
        $this->asGuest()->getJson('/api/v1/artisans/'.$artisan->uuid)->assertNotFound();

        $this->asUser($this->moderator)->patchJson($this->admin("artisans/{$artisan->uuid}/validate"))->assertOk();

        $this->asGuest()->getJson('/api/v1/artisans')->assertOk()->assertJsonPath('meta.total', 1);
        $this->asGuest()->getJson('/api/v1/artisans/'.$artisan->uuid)->assertOk();
        $this->asGuest()->getJson('/api/v1/search/artisans')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_moderators_and_admins_have_the_same_rights(): void
    {
        foreach ([$this->admin, $this->moderator] as $staff) {
            $artisan = $this->makeArtisan('pending');

            $this->asUser($staff)->patchJson($this->admin("artisans/{$artisan->uuid}/validate"))
                ->assertOk()
                ->assertJsonPath('data.validated_by.role', $staff->role->value);
        }
    }

    public function test_a_staff_member_cannot_moderate_their_own_profile(): void
    {
        $pending = $this->makeArtisan('pending', $this->moderator);
        $validated = $this->makeArtisan('validated', User::factory()->admin()->create(), [], $this->neighborhood);
        $own = $this->makeArtisan('validated', $this->admin);

        $this->asUser($this->moderator)->patchJson($this->admin("artisans/{$pending->uuid}/validate"))
            ->assertForbidden()->assertExactJson(['message' => ApiMessages::FORBIDDEN]);
        $this->asUser($this->moderator)->patchJson($this->admin("artisans/{$pending->uuid}/reject"), ['reason' => 'Motif de test'])->assertForbidden();
        $this->asUser($this->admin)->patchJson($this->admin("artisans/{$own->uuid}/suspend"), ['reason' => 'Motif de test'])->assertForbidden();

        $this->assertSame(ArtisanStatus::Pending, $pending->fresh()->status);
        $this->assertSame(ArtisanStatus::Validated, $own->fresh()->status);

        // Another staff member can.
        $this->asUser($this->admin)->patchJson($this->admin("artisans/{$pending->uuid}/validate"))->assertOk();
        $this->asUser($this->moderator)->patchJson($this->admin("artisans/{$validated->uuid}/suspend"), ['reason' => 'Motif de test'])->assertOk();
    }

    public function test_the_client_cannot_inject_the_status_or_the_validation_trace(): void
    {
        $artisan = $this->makeArtisan('pending');

        $this->asUser($this->admin)->patchJson($this->admin("artisans/{$artisan->uuid}/validate"), [
            'status' => 'suspended',
            'validated_by' => 999999,
            'validated_at' => '2020-01-01 00:00:00',
            'user_id' => 999999,
            'status_reason' => 'injected',
        ])->assertOk()->assertJsonPath('data.status', 'validated');

        $fresh = $artisan->fresh();

        $this->assertSame(ArtisanStatus::Validated, $fresh->status);
        $this->assertSame($this->admin->id, $fresh->validated_by);
        $this->assertGreaterThan(2025, $fresh->validated_at->year);
        $this->assertNull($fresh->user_id);
        $this->assertNull($fresh->status_reason);
    }

    public function test_a_second_validation_is_refused_and_changes_nothing(): void
    {
        $artisan = $this->makeArtisan('pending');
        $url = $this->admin("artisans/{$artisan->uuid}/validate");

        $this->asUser($this->admin)->patchJson($url)->assertOk();

        $validatedAt = $artisan->fresh()->validated_at;

        $this->asUser($this->moderator)->patchJson($url)
            ->assertStatus(409)
            ->assertExactJson(['message' => ApiMessages::INVALID_TRANSITION]);

        $this->assertSame($this->admin->id, $artisan->fresh()->validated_by);
        $this->assertEquals($validatedAt, $artisan->fresh()->validated_at);
    }

    // ------------------------------------------------------------------ reject

    public function test_a_pending_profile_is_rejected_with_a_reason_the_owner_can_read_and_fix(): void
    {
        $owner = User::factory()->create();
        $artisan = $this->makeArtisan('pending', $owner);

        $this->asUser($this->admin)->patchJson($this->admin("artisans/{$artisan->uuid}/reject"), ['reason' => 'Photos manquantes'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.status_reason', 'Photos manquantes');

        $fresh = $artisan->fresh();
        $this->assertSame(ArtisanStatus::Rejected, $fresh->status);
        $this->assertNull($fresh->validated_by);
        $this->assertNull($fresh->validated_at);

        $this->asUser($owner)->getJson('/api/v1/artisans/'.$artisan->uuid)
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.status_reason', 'Photos manquantes');

        $this->asGuest()->getJson('/api/v1/artisans/'.$artisan->uuid)->assertNotFound();

        $this->asUser($owner)->postJson('/api/v1/artisans/'.$artisan->uuid.'/submit')
            ->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.status_reason', null);

        $this->asUser($this->moderator)->patchJson($this->admin("artisans/{$artisan->uuid}/validate"))->assertOk();
    }

    public function test_rejecting_and_suspending_require_a_valid_reason(): void
    {
        $pending = $this->makeArtisan('pending');
        $validated = $this->makeArtisan('validated');

        $invalid = [[], ['reason' => ''], ['reason' => '   '], ['reason' => 'ab'], ['reason' => str_repeat('a', 501)], ['reason' => ['x']]];

        foreach ($invalid as $payload) {
            $this->asUser($this->admin)->patchJson($this->admin("artisans/{$pending->uuid}/reject"), $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['reason'])
                ->assertJsonPath('message', ApiMessages::INVALID_DATA);

            $this->asUser($this->admin)->patchJson($this->admin("artisans/{$validated->uuid}/suspend"), $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['reason']);
        }

        $this->assertSame(ArtisanStatus::Pending, $pending->fresh()->status);
        $this->assertSame(ArtisanStatus::Validated, $validated->fresh()->status);
    }

    // ------------------------------------------------------------------ suspend

    public function test_a_validated_profile_can_be_suspended_and_reinstated(): void
    {
        $owner = User::factory()->create();
        $artisan = $this->makeArtisan('validated', $owner);

        $this->asGuest()->getJson('/api/v1/artisans')->assertJsonPath('meta.total', 1);

        $this->asUser($this->admin)->patchJson($this->admin("artisans/{$artisan->uuid}/suspend"), ['reason' => 'Signalements repetes'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.status_reason', 'Signalements repetes');

        $this->asGuest()->getJson('/api/v1/artisans')->assertJsonPath('meta.total', 0);
        $this->asGuest()->getJson('/api/v1/search/artisans')->assertJsonPath('meta.total', 0);
        $this->asGuest()->getJson('/api/v1/artisans/'.$artisan->uuid)->assertNotFound();

        $this->asUser($owner)->patchJson('/api/v1/artisans/'.$artisan->uuid, ['description' => 'x'])->assertForbidden();

        $this->asUser($this->moderator)->patchJson($this->admin("artisans/{$artisan->uuid}/validate"))
            ->assertOk()
            ->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.status_reason', null);

        $this->asGuest()->getJson('/api/v1/artisans')->assertJsonPath('meta.total', 1);
    }

    // ------------------------------------------------------------------ transitions

    public function test_forbidden_transitions_are_refused_with_a_409_and_change_nothing(): void
    {
        $matrix = [
            'validate' => ['draft', 'rejected', 'validated'],
            'reject' => ['draft', 'validated', 'rejected', 'suspended'],
            'suspend' => ['draft', 'pending', 'rejected', 'suspended'],
        ];

        foreach ($matrix as $action => $states) {
            foreach ($states as $state) {
                $artisan = $this->makeArtisan($state);

                $this->asUser($this->admin)->patchJson($this->admin("artisans/{$artisan->uuid}/{$action}"), ['reason' => 'Motif de test'])
                    ->assertStatus(409)
                    ->assertExactJson(['message' => ApiMessages::INVALID_TRANSITION]);

                $this->assertSame(ArtisanStatus::from($state), $artisan->fresh()->status, "{$action} from {$state}");
            }
        }
    }

    public function test_unknown_profiles_are_a_404_for_every_action(): void
    {
        $uuid = '00000000-0000-4000-8000-000000000000';

        foreach (['validate', 'reject', 'suspend'] as $action) {
            $this->asUser($this->admin)->patchJson($this->admin("artisans/{$uuid}/{$action}"), ['reason' => 'Motif de test'])
                ->assertNotFound()
                ->assertExactJson(['message' => ApiMessages::NOT_FOUND]);
        }
    }

    public function test_every_status_change_is_written_to_the_log(): void
    {
        Log::spy();

        $artisan = $this->makeArtisan('pending');

        $this->asUser($this->admin)->patchJson($this->admin("artisans/{$artisan->uuid}/validate"))->assertOk();

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message, $context = []) => $message === 'artisan.status_changed'
                && ($context['artisan'] ?? null) === $artisan->uuid
                && ($context['actor'] ?? null) === $this->admin->uuid
                && ($context['from'] ?? null) === 'pending'
                && ($context['to'] ?? null) === 'validated')
            ->once();
    }

    // ------------------------------------------------------------------ reports

    public function test_the_report_list_exposes_the_documented_fields(): void
    {
        $artisan = $this->makeArtisan('validated', null, ['business_name' => 'Atelier Kofi']);
        $reporter = User::factory()->create(['name' => 'Ama Test'])->refresh();
        $this->makeReport($artisan, ['user_id' => $reporter->id, 'description' => 'Numero errone']);
        $this->makeReport($artisan, ['user_id' => null]);

        $rows = $this->asUser($this->admin)->getJson($this->admin('reports'))
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta' => ['current_page', 'per_page', 'total']])
            ->assertJsonPath('meta.total', 2)
            ->json('data');

        foreach ($rows as $row) {
            $this->assertEqualsCanonicalizing(
                ['uuid', 'reason', 'description', 'status', 'artisan', 'reporter', 'resolved_by', 'resolved_at', 'created_at'],
                array_keys($row)
            );
            $this->assertSame('open', $row['status']);
            $this->assertNull($row['resolved_by']);
            $this->assertNull($row['resolved_at']);
            $this->assertSame('Atelier Kofi', $row['artisan']['business_name']);
            $this->assertEqualsCanonicalizing(['uuid', 'business_name', 'status'], array_keys($row['artisan']));
        }

        $named = collect($rows)->firstWhere('description', 'Numero errone');
        $this->assertSame('Ama Test', $named['reporter']['name']);
        $this->assertEqualsCanonicalizing(['uuid', 'name'], array_keys($named['reporter']));
        $this->assertNull(collect($rows)->firstWhere('description', '!==', 'Numero errone')['reporter'] ?? null);
    }

    public function test_reports_can_be_filtered_and_the_open_queue_is_oldest_first(): void
    {
        $artisan = $this->makeArtisan('validated');
        $other = $this->makeArtisan('validated');

        $recent = $this->makeReport($artisan, ['created_at' => now()->subDay()]);
        $oldest = $this->makeReport($artisan, ['created_at' => now()->subDays(3), 'reason' => 'wrong_contact', 'description' => 'Details']);
        $closed = $this->makeReport($other, ['created_at' => now()->subDays(2)]);
        $closed->forceFill(['status' => ReportStatus::Resolved, 'resolved_at' => now()])->save();

        $uuids = fn (string $query): array => $this->asUser($this->admin)
            ->getJson($this->admin('reports').$query)->assertOk()->json('data.*.uuid');

        $this->assertSame([$recent->uuid, $closed->uuid, $oldest->uuid], $uuids(''));
        $this->assertSame([$oldest->uuid, $recent->uuid], $uuids('?status=open'));
        $this->assertSame([$closed->uuid], $uuids('?status=resolved'));
        $this->assertSame([$oldest->uuid], $uuids('?reason=wrong_contact'));
        $this->assertEqualsCanonicalizing([$recent->uuid, $oldest->uuid], $uuids('?artisan='.$artisan->uuid));
        $this->assertSame([$closed->uuid], $uuids('?artisan='.$other->uuid));
    }

    public function test_report_list_parameters_are_validated(): void
    {
        foreach (['per_page=0', 'per_page=101', 'page=0', 'status=archived', 'reason=spam', 'artisan=not-a-uuid'] as $query) {
            $this->asUser($this->admin)->getJson($this->admin('reports').'?'.$query)
                ->assertUnprocessable()
                ->assertJsonPath('message', ApiMessages::INVALID_DATA);
        }
    }

    public function test_an_open_report_can_be_resolved_or_dismissed(): void
    {
        $artisan = $this->makeArtisan('validated');
        $toResolve = $this->makeReport($artisan);
        $toDismiss = $this->makeReport($artisan, ['user_id' => null]);

        $this->asUser($this->admin)->patchJson($this->admin("reports/{$toResolve->uuid}"), ['status' => 'resolved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.resolved_by.uuid', $this->admin->uuid);

        $this->asUser($this->moderator)->patchJson($this->admin("reports/{$toDismiss->uuid}"), ['status' => 'dismissed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'dismissed')
            ->assertJsonPath('data.resolved_by.uuid', $this->moderator->uuid);

        $resolved = $toResolve->fresh();
        $this->assertSame(ReportStatus::Resolved, $resolved->status);
        $this->assertSame($this->admin->id, $resolved->resolved_by);
        $this->assertTrue($resolved->resolved_at->isToday());
        $this->assertSame(ReportStatus::Dismissed, $toDismiss->fresh()->status);

        // Closing a report does not touch the profile.
        $this->assertSame(ArtisanStatus::Validated, $artisan->fresh()->status);
    }

    public function test_a_closed_report_cannot_be_closed_again(): void
    {
        $report = $this->makeReport($this->makeArtisan('validated'));
        $url = $this->admin("reports/{$report->uuid}");

        $this->asUser($this->admin)->patchJson($url, ['status' => 'resolved'])->assertOk();

        foreach (['resolved', 'dismissed'] as $status) {
            $this->asUser($this->moderator)->patchJson($url, ['status' => $status])
                ->assertStatus(409)
                ->assertExactJson(['message' => ApiMessages::INVALID_TRANSITION]);
        }

        $fresh = $report->fresh();
        $this->assertSame(ReportStatus::Resolved, $fresh->status);
        $this->assertSame($this->admin->id, $fresh->resolved_by);
    }

    public function test_the_report_status_must_be_resolved_or_dismissed(): void
    {
        $report = $this->makeReport($this->makeArtisan('validated'));
        $url = $this->admin("reports/{$report->uuid}");

        foreach ([[], ['status' => ''], ['status' => 'open'], ['status' => 'archived'], ['status' => ['resolved']]] as $payload) {
            $this->asUser($this->admin)->patchJson($url, $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['status'])
                ->assertJsonPath('message', ApiMessages::INVALID_DATA);
        }

        $this->assertSame(ReportStatus::Open, $report->fresh()->status);
        $this->assertNull($report->fresh()->resolved_by);
    }

    public function test_the_client_cannot_choose_who_closed_the_report(): void
    {
        $report = $this->makeReport($this->makeArtisan('validated'));
        $someoneElse = User::factory()->moderator()->create();

        $this->asUser($this->admin)->patchJson($this->admin("reports/{$report->uuid}"), [
            'status' => 'resolved',
            'resolved_by' => $someoneElse->id,
            'resolved_at' => '2020-01-01 00:00:00',
        ])->assertOk();

        $fresh = $report->fresh();
        $this->assertSame($this->admin->id, $fresh->resolved_by);
        $this->assertGreaterThan(2025, $fresh->resolved_at->year);
    }

    public function test_an_unknown_report_is_a_404(): void
    {
        $this->asUser($this->admin)->patchJson($this->admin('reports/00000000-0000-4000-8000-000000000000'), ['status' => 'resolved'])
            ->assertNotFound()
            ->assertExactJson(['message' => ApiMessages::NOT_FOUND]);

        $this->asUser($this->admin)->patchJson($this->admin('reports/not-a-uuid'), ['status' => 'resolved'])->assertNotFound();
    }

    public function test_closing_a_report_is_written_to_the_log_and_hides_nothing_internal(): void
    {
        Log::spy();

        $report = $this->makeReport($this->makeArtisan('validated'));

        $response = $this->asUser($this->admin)->patchJson($this->admin("reports/{$report->uuid}"), ['status' => 'dismissed'])->assertOk();

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message, $context = []) => $message === 'report.closed'
                && ($context['report'] ?? null) === $report->uuid
                && ($context['to'] ?? null) === 'dismissed')
            ->once();

        foreach (['id', 'artisan_id', 'user_id', 'resolved_by_id'] as $key) {
            $response->assertJsonMissingPath("data.{$key}");
        }
    }
}
