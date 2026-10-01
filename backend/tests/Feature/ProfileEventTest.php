<?php

namespace Tests\Feature;

use App\Enums\ProfileEventType;
use App\Models\Artisan;
use App\Models\ProfileEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\AssertsDatabaseRejections;
use Tests\TestCase;

class ProfileEventTest extends TestCase
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
    private function rawEvent(array $overrides = []): array
    {
        return array_merge([
            'artisan_id' => $this->profile->id,
            'event_type' => 'profile_view',
        ], $overrides);
    }

    public function test_artisan_has_many_profile_events(): void
    {
        ProfileEvent::factory()->count(3)->for($this->profile)->create();

        $this->assertCount(3, $this->profile->profileEvents);
        $this->assertTrue($this->profile->profileEvents->first()->artisan->is($this->profile));
    }

    public function test_every_event_type_is_accepted(): void
    {
        foreach (ProfileEventType::cases() as $type) {
            $event = ProfileEvent::factory()->for($this->profile)->ofType($type)->create();

            $this->assertSame($type, $event->fresh()->event_type);
        }

        $this->assertSame(6, $this->profile->profileEvents()->count());
    }

    public function test_database_rejects_an_unknown_event_type(): void
    {
        $this->assertRejected(
            fn () => DB::table('profile_events')->insert($this->rawEvent(['event_type' => 'ad_click'])),
            'unknown event type'
        );
    }

    public function test_user_is_optional(): void
    {
        $user = User::factory()->create();

        $anonymous = ProfileEvent::factory()->for($this->profile)->create();
        $identified = ProfileEvent::factory()->for($this->profile)->for($user)->create();

        $this->assertNull($anonymous->fresh()->user);
        $this->assertTrue($identified->fresh()->user->is($user));
    }

    public function test_metadata_is_optional_and_stored_as_a_json_object(): void
    {
        $without = ProfileEvent::factory()->for($this->profile)->create();
        $with = ProfileEvent::factory()->for($this->profile)->withMetadata(['platform' => 'android', 'screen' => 'search'])->create();

        $this->assertNull($without->fresh()->metadata);
        $this->assertEquals(['platform' => 'android', 'screen' => 'search'], $with->fresh()->metadata);
    }

    public function test_metadata_must_be_a_json_object(): void
    {
        $this->assertRejected(
            fn () => DB::table('profile_events')->insert($this->rawEvent(['metadata' => '[1, 2]'])),
            'JSON array as metadata'
        );
        $this->assertRejected(
            fn () => DB::table('profile_events')->insert($this->rawEvent(['metadata' => '"text"'])),
            'JSON string as metadata'
        );

        DB::table('profile_events')->insert($this->rawEvent(['metadata' => '{"platform": "windows"}']));

        $this->assertEquals(['platform' => 'windows'], ProfileEvent::firstOrFail()->metadata);
    }

    public function test_created_at_is_filled_by_the_database_and_there_is_no_updated_at(): void
    {
        DB::table('profile_events')->insert($this->rawEvent());

        $this->assertNotNull(ProfileEvent::firstOrFail()->created_at);
        $this->assertFalse(Schema::hasColumn('profile_events', 'updated_at'));
    }

    public function test_events_can_be_counted_per_artisan_type_and_period(): void
    {
        ProfileEvent::factory()->count(2)->for($this->profile)->at(now()->subDays(3))->create();
        ProfileEvent::factory()->for($this->profile)->create();
        ProfileEvent::factory()->for($this->profile)->ofType(ProfileEventType::PhoneClick)->create();
        ProfileEvent::factory()->for(Artisan::factory()->create())->create();

        $views = fn (int $days) => ProfileEvent::where('artisan_id', $this->profile->id)
            ->where('event_type', ProfileEventType::ProfileView->value)
            ->where('created_at', '>=', now()->subDays($days))
            ->count();

        $this->assertSame(3, $views(7));
        $this->assertSame(1, $views(1));
        $this->assertSame(1, ProfileEvent::where('artisan_id', $this->profile->id)
            ->where('event_type', ProfileEventType::PhoneClick->value)->count());
    }

    public function test_indexes_for_a_large_table_exist(): void
    {
        $definitions = collect(DB::select("SELECT indexdef FROM pg_indexes WHERE tablename = 'profile_events'"))
            ->pluck('indexdef')
            ->implode("\n");

        $this->assertStringContainsString('(artisan_id, event_type, created_at)', $definitions);
        $this->assertStringContainsString('USING brin (created_at)', $definitions);
        $this->assertStringContainsString('WHERE (user_id IS NOT NULL)', $definitions);
    }

    public function test_ownership_is_not_mass_assignable(): void
    {
        $event = new ProfileEvent;

        $this->assertFalse($event->isFillable('artisan_id'));
        $this->assertFalse($event->isFillable('user_id'));
        $this->assertTrue($event->isFillable('event_type'));
        $this->assertTrue($event->isFillable('metadata'));
    }

    public function test_events_survive_the_permanent_deletion_of_their_user(): void
    {
        $user = User::factory()->create();
        $event = ProfileEvent::factory()->for($this->profile)->for($user)->create();

        $user->forceDelete();

        $this->assertNotNull($event->fresh());
        $this->assertNull($event->fresh()->user_id);
    }

    public function test_permanently_deleting_an_artisan_removes_its_events(): void
    {
        $event = ProfileEvent::factory()->for($this->profile)->create();

        $this->profile->forceDelete();

        $this->assertModelMissing($event);
    }

    public function test_soft_deleting_an_artisan_keeps_its_events(): void
    {
        $event = ProfileEvent::factory()->for($this->profile)->create();

        $this->profile->delete();

        $this->assertModelExists($event);
    }
}
