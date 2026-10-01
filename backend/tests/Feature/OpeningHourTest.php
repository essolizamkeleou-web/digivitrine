<?php

namespace Tests\Feature;

use App\Models\Artisan;
use App\Models\OpeningHour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AssertsDatabaseRejections;
use Tests\TestCase;

class OpeningHourTest extends TestCase
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
     * Minimal valid open slot for raw inserts (bypasses the model on purpose).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rawHour(array $overrides = []): array
    {
        return array_merge([
            'artisan_id' => $this->profile->id,
            'day_of_week' => 1,
            'opens_at' => '08:00',
            'closes_at' => '17:00',
        ], $overrides);
    }

    public function test_artisan_has_many_opening_hours(): void
    {
        OpeningHour::factory()->for($this->profile)->forDay(1)->create();
        OpeningHour::factory()->for($this->profile)->forDay(2)->create();

        $this->assertCount(2, $this->profile->openingHours);
        $this->assertTrue($this->profile->openingHours->first()->artisan->is($this->profile));
    }

    public function test_times_are_stored_and_returned_as_time_strings(): void
    {
        $slot = OpeningHour::factory()->for($this->profile)->create()->fresh();

        $this->assertSame('08:00:00', $slot->opens_at);
        $this->assertSame('17:00:00', $slot->closes_at);
        $this->assertFalse($slot->is_closed);
    }

    public function test_database_defaults_a_slot_to_open(): void
    {
        DB::table('opening_hours')->insert($this->rawHour());

        $this->assertFalse(OpeningHour::firstOrFail()->is_closed);
    }

    public function test_day_of_week_must_be_between_one_and_seven(): void
    {
        foreach ([0, 8] as $day) {
            $this->assertRejected(
                fn () => DB::table('opening_hours')->insert($this->rawHour(['day_of_week' => $day])),
                "day {$day}"
            );
        }

        foreach ([1, 7] as $day) {
            DB::table('opening_hours')->insert($this->rawHour(['day_of_week' => $day]));
        }

        $this->assertSame(2, OpeningHour::count());
    }

    public function test_a_closed_day_has_no_times(): void
    {
        $closed = OpeningHour::factory()->for($this->profile)->forDay(7)->closed()->create()->fresh();

        $this->assertTrue($closed->is_closed);
        $this->assertNull($closed->opens_at);
        $this->assertNull($closed->closes_at);

        $this->assertRejected(
            fn () => DB::table('opening_hours')->insert($this->rawHour(['day_of_week' => 6, 'is_closed' => true])),
            'closed day that still has times'
        );
    }

    public function test_an_open_day_requires_both_times(): void
    {
        $this->assertRejected(
            fn () => DB::table('opening_hours')->insert($this->rawHour(['opens_at' => null])),
            'open day without opening time'
        );
        $this->assertRejected(
            fn () => DB::table('opening_hours')->insert($this->rawHour(['closes_at' => null])),
            'open day without closing time'
        );
    }

    public function test_closing_time_must_be_after_opening_time(): void
    {
        $this->assertRejected(
            fn () => DB::table('opening_hours')->insert($this->rawHour(['opens_at' => '17:00', 'closes_at' => '08:00'])),
            'closing before opening'
        );
        $this->assertRejected(
            fn () => DB::table('opening_hours')->insert($this->rawHour(['opens_at' => '08:00', 'closes_at' => '08:00'])),
            'closing at the opening time'
        );
    }

    public function test_a_day_can_have_several_slots(): void
    {
        OpeningHour::factory()->for($this->profile)->between('08:00', '12:00')->create();
        OpeningHour::factory()->for($this->profile)->between('14:00', '18:00')->create();

        $this->assertSame(2, $this->profile->openingHours()->where('day_of_week', 1)->count());
    }

    public function test_the_same_slot_cannot_be_recorded_twice(): void
    {
        OpeningHour::factory()->for($this->profile)->between('08:00', '12:00')->create();

        $this->assertRejected(
            fn () => OpeningHour::factory()->for($this->profile)->between('08:00', '13:00')->create(),
            'two slots opening at the same time'
        );
    }

    public function test_a_day_can_be_marked_closed_only_once(): void
    {
        OpeningHour::factory()->for($this->profile)->forDay(7)->closed()->create();

        $this->assertRejected(
            fn () => OpeningHour::factory()->for($this->profile)->forDay(7)->closed()->create(),
            'two closed rows for the same day'
        );

        OpeningHour::factory()->for($this->profile)->forDay(6)->closed()->create();

        $this->assertSame(2, OpeningHour::where('is_closed', true)->count());
    }

    public function test_two_artisans_can_have_the_same_hours(): void
    {
        OpeningHour::factory()->for($this->profile)->create();
        OpeningHour::factory()->for(Artisan::factory()->create())->create();

        $this->assertSame(2, OpeningHour::count());
    }

    public function test_permanently_deleting_an_artisan_removes_its_hours(): void
    {
        $slot = OpeningHour::factory()->for($this->profile)->create();

        $this->profile->forceDelete();

        $this->assertDatabaseMissing('opening_hours', ['id' => $slot->id]);
    }

    public function test_soft_deleting_an_artisan_keeps_its_hours(): void
    {
        $slot = OpeningHour::factory()->for($this->profile)->create();

        $this->profile->delete();

        $this->assertDatabaseHas('opening_hours', ['id' => $slot->id]);
    }
}
