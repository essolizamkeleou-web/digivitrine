<?php

namespace Tests\Feature;

use App\Enums\ArtisanStatus;
use App\Enums\UserRole;
use App\Models\Artisan;
use App\Models\Neighborhood;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\AssertsDatabaseRejections;
use Tests\TestCase;

class ArtisanModelTest extends TestCase
{
    use AssertsDatabaseRejections;
    use RefreshDatabase;

    private User $agent;

    private Neighborhood $neighborhood;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = User::factory()->fieldAgent()->create();
        $this->neighborhood = Neighborhood::factory()->create();
    }

    /**
     * Minimal valid row for raw inserts (bypasses the model on purpose).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rawArtisan(array $overrides = []): array
    {
        return array_merge([
            'created_by' => $this->agent->id,
            'neighborhood_id' => $this->neighborhood->id,
            'business_name' => 'Atelier Test',
            'phone' => '+22890000011',
        ], $overrides);
    }

    public function test_field_agent_can_create_a_profile_without_owner_account(): void
    {
        $artisan = Artisan::factory()->create(['created_by' => $this->agent->id]);

        $this->assertNull($artisan->user_id);
        $this->assertNull($artisan->owner);
        $this->assertTrue($artisan->creator->is($this->agent));
    }

    public function test_profile_can_be_owned_by_a_user_account(): void
    {
        $owner = User::factory()->artisan()->create();
        $artisan = Artisan::factory()->ownedBy($owner)->create();

        $this->assertTrue($artisan->owner->is($owner));
        $this->assertTrue($owner->artisan->is($artisan));
    }

    public function test_relations_to_creator_validator_and_neighborhood(): void
    {
        $artisan = Artisan::factory()->validated()->create([
            'neighborhood_id' => $this->neighborhood->id,
            'created_by' => $this->agent->id,
        ]);

        $this->assertSame(UserRole::Moderator, $artisan->validator->role);
        $this->assertTrue($artisan->neighborhood->is($this->neighborhood));
        $this->assertTrue($this->neighborhood->artisans->first()->is($artisan));
        $this->assertTrue($this->agent->createdArtisans->first()->is($artisan));
    }

    public function test_model_defaults_status_to_draft(): void
    {
        $this->assertSame(ArtisanStatus::Draft, (new Artisan)->status);
    }

    public function test_database_applies_default_status_and_uuid(): void
    {
        DB::table('artisans')->insert($this->rawArtisan());

        $artisan = Artisan::firstOrFail();

        $this->assertSame(ArtisanStatus::Draft, $artisan->status);
        $this->assertTrue(Str::isUuid($artisan->uuid));
        $this->assertNull($artisan->user_id);
    }

    public function test_status_is_cast_to_the_enum(): void
    {
        $artisan = Artisan::factory()->pending()->create();

        $this->assertSame(ArtisanStatus::Pending, $artisan->fresh()->status);
    }

    public function test_publicly_visible_scope_returns_only_validated_profiles(): void
    {
        Artisan::factory()->draft()->create();
        Artisan::factory()->pending()->create();
        $validated = Artisan::factory()->validated()->create();
        Artisan::factory()->rejected()->create();
        Artisan::factory()->suspended()->create();

        $visible = Artisan::publiclyVisible()->get();

        $this->assertCount(1, $visible);
        $this->assertTrue($visible->first()->is($validated));
    }

    public function test_ownership_and_status_fields_are_not_mass_assignable(): void
    {
        $artisan = new Artisan;

        foreach (['uuid', 'user_id', 'created_by', 'validated_by', 'validated_at', 'status', 'status_reason'] as $field) {
            $this->assertFalse($artisan->isFillable($field), "{$field} must not be mass assignable");
        }

        foreach (['neighborhood_id', 'business_name', 'phone', 'whatsapp_phone', 'latitude', 'longitude'] as $field) {
            $this->assertTrue($artisan->isFillable($field), "{$field} should be mass assignable");
        }
    }

    public function test_database_rejects_an_unknown_status(): void
    {
        $this->assertRejected(
            fn () => DB::table('artisans')->insert($this->rawArtisan(['status' => 'archived'])),
            'unknown status'
        );
    }

    public function test_rejected_and_suspended_profiles_require_a_reason(): void
    {
        foreach (['rejected', 'suspended'] as $status) {
            $this->assertRejected(
                fn () => DB::table('artisans')->insert($this->rawArtisan(['status' => $status])),
                "{$status} without reason"
            );
            $this->assertRejected(
                fn () => DB::table('artisans')->insert($this->rawArtisan(['status' => $status, 'status_reason' => '   '])),
                "{$status} with a blank reason"
            );
        }

        DB::table('artisans')->insert($this->rawArtisan([
            'status' => 'rejected',
            'status_reason' => 'Photos manquantes',
        ]));

        $this->assertDatabaseHas('artisans', ['status' => 'rejected']);
    }

    public function test_validated_profiles_must_record_who_validated_and_when(): void
    {
        $this->assertRejected(
            fn () => DB::table('artisans')->insert($this->rawArtisan(['status' => 'validated'])),
            'validated without validator and date'
        );
        $this->assertRejected(
            fn () => DB::table('artisans')->insert($this->rawArtisan(['status' => 'validated', 'validated_by' => $this->agent->id])),
            'validated without date'
        );

        DB::table('artisans')->insert($this->rawArtisan([
            'status' => 'validated',
            'validated_by' => $this->agent->id,
            'validated_at' => now()->toDateTimeString(),
        ]));

        $this->assertDatabaseHas('artisans', ['status' => 'validated']);
    }

    public function test_phone_must_use_the_togolese_international_format(): void
    {
        foreach (['90123456', '+22890123', '+2289012345678', '+33612345678', '0022890123456', '+228 90123456'] as $phone) {
            $this->assertRejected(
                fn () => DB::table('artisans')->insert($this->rawArtisan(['phone' => $phone])),
                "phone {$phone}"
            );
        }

        DB::table('artisans')->insert($this->rawArtisan(['phone' => '+22890123456']));

        $this->assertDatabaseHas('artisans', ['phone' => '+22890123456']);
    }

    public function test_whatsapp_number_is_optional_but_must_use_the_same_format(): void
    {
        $this->assertRejected(
            fn () => DB::table('artisans')->insert($this->rawArtisan(['whatsapp_phone' => '+33612345678'])),
            'foreign whatsapp number'
        );

        DB::table('artisans')->insert($this->rawArtisan(['whatsapp_phone' => '+22890123456']));

        $this->assertDatabaseHas('artisans', ['whatsapp_phone' => '+22890123456']);
    }

    public function test_business_name_cannot_be_blank(): void
    {
        $this->assertRejected(
            fn () => DB::table('artisans')->insert($this->rawArtisan(['business_name' => '   '])),
            'blank business name'
        );
    }

    public function test_coordinates_must_be_given_together_and_within_range(): void
    {
        $this->assertRejected(
            fn () => DB::table('artisans')->insert($this->rawArtisan(['latitude' => 6.13])),
            'latitude without longitude'
        );
        $this->assertRejected(
            fn () => DB::table('artisans')->insert($this->rawArtisan(['longitude' => 1.22])),
            'longitude without latitude'
        );
        $this->assertRejected(
            fn () => DB::table('artisans')->insert($this->rawArtisan(['latitude' => 91, 'longitude' => 1.22])),
            'latitude out of range'
        );
        $this->assertRejected(
            fn () => DB::table('artisans')->insert($this->rawArtisan(['latitude' => 6.13, 'longitude' => 181])),
            'longitude out of range'
        );

        DB::table('artisans')->insert($this->rawArtisan(['latitude' => 6.13, 'longitude' => 1.22]));

        $this->assertDatabaseHas('artisans', ['latitude' => 6.13, 'longitude' => 1.22]);
    }

    public function test_coordinates_are_returned_with_six_decimals(): void
    {
        $artisan = Artisan::factory()->withLocation(6.1319, 1.2228)->create();

        $this->assertSame('6.131900', $artisan->fresh()->latitude);
        $this->assertSame('1.222800', $artisan->fresh()->longitude);
    }

    public function test_phone_is_unique_among_active_profiles(): void
    {
        Artisan::factory()->create(['phone' => '+22890000021']);

        $this->assertRejected(
            fn () => Artisan::factory()->create(['phone' => '+22890000021']),
            'duplicate phone'
        );
    }

    public function test_phone_can_be_reused_after_soft_deletion(): void
    {
        $first = Artisan::factory()->create(['phone' => '+22890000022']);
        $first->delete();

        $second = Artisan::factory()->create(['phone' => '+22890000022']);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSoftDeleted($first);
    }

    public function test_user_owns_at_most_one_active_profile(): void
    {
        $owner = User::factory()->artisan()->create();
        $first = Artisan::factory()->ownedBy($owner)->create();

        $this->assertRejected(
            fn () => Artisan::factory()->ownedBy($owner)->create(),
            'second active profile for the same owner'
        );

        $first->delete();

        $second = Artisan::factory()->ownedBy($owner)->create();

        $this->assertTrue($owner->fresh()->artisan->is($second));
    }

    public function test_neighborhood_in_use_cannot_be_deleted(): void
    {
        Artisan::factory()->create(['neighborhood_id' => $this->neighborhood->id]);

        $this->assertRejected(fn () => $this->neighborhood->delete(), 'deleting a neighborhood that has profiles');
    }

    public function test_creator_account_cannot_be_permanently_deleted(): void
    {
        Artisan::factory()->create(['created_by' => $this->agent->id]);

        $this->assertRejected(fn () => $this->agent->forceDelete(), 'deleting the creator of a profile');
    }

    public function test_deleting_the_owner_account_keeps_the_profile(): void
    {
        $owner = User::factory()->artisan()->create();
        $artisan = Artisan::factory()->ownedBy($owner)->create();

        $owner->forceDelete();

        $this->assertNotNull($artisan->fresh());
        $this->assertNull($artisan->fresh()->user_id);
    }

    public function test_profiles_are_soft_deleted(): void
    {
        $artisan = Artisan::factory()->create();

        $artisan->delete();

        $this->assertSoftDeleted($artisan);
    }
}
