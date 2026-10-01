<?php

namespace Tests\Feature;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Models\Artisan;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\AssertsDatabaseRejections;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use AssertsDatabaseRejections;
    use RefreshDatabase;

    private Artisan $profile;

    private User $reporter;

    private User $moderator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->profile = Artisan::factory()->create();
        $this->reporter = User::factory()->create();
        $this->moderator = User::factory()->moderator()->create();
    }

    /**
     * Minimal valid row for raw inserts (bypasses the model on purpose).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rawReport(array $overrides = []): array
    {
        return array_merge([
            'artisan_id' => $this->profile->id,
            'reason' => 'false_information',
        ], $overrides);
    }

    public function test_user_can_report_an_artisan(): void
    {
        $report = Report::factory()->for($this->profile)->for($this->reporter, 'reporter')->create();

        $this->assertTrue($report->artisan->is($this->profile));
        $this->assertTrue($report->reporter->is($this->reporter));
        $this->assertTrue($this->profile->reports->first()->is($report));
        $this->assertTrue($this->reporter->reports->first()->is($report));
    }

    public function test_reporter_is_optional(): void
    {
        $report = Report::factory()->for($this->profile)->anonymous()->create();

        $this->assertNull($report->fresh()->user_id);
        $this->assertNull($report->fresh()->reporter);
    }

    public function test_every_reason_is_accepted(): void
    {
        foreach (ReportReason::cases() as $reason) {
            $report = Report::factory()->for($this->profile)->create([
                'reason' => $reason,
                'description' => 'Details of the problem',
            ]);

            $this->assertSame($reason, $report->fresh()->reason);
        }

        $this->assertSame(5, $this->profile->reports()->count());
    }

    public function test_database_rejects_an_unknown_reason_or_status(): void
    {
        $this->assertRejected(
            fn () => DB::table('reports')->insert($this->rawReport(['reason' => 'spam'])),
            'unknown reason'
        );
        $this->assertRejected(
            fn () => DB::table('reports')->insert($this->rawReport(['status' => 'archived'])),
            'unknown status'
        );
    }

    public function test_the_other_reason_requires_a_description(): void
    {
        $this->assertRejected(
            fn () => DB::table('reports')->insert($this->rawReport(['reason' => 'other'])),
            'other without description'
        );
        $this->assertRejected(
            fn () => DB::table('reports')->insert($this->rawReport(['reason' => 'other', 'description' => '   '])),
            'other with a blank description'
        );

        DB::table('reports')->insert($this->rawReport(['reason' => 'other', 'description' => 'Le local est ferme']));
        DB::table('reports')->insert($this->rawReport(['reason' => 'wrong_contact']));

        $this->assertSame(2, Report::count());
    }

    public function test_a_description_cannot_be_blank(): void
    {
        $this->assertRejected(
            fn () => DB::table('reports')->insert($this->rawReport(['description' => '   '])),
            'blank description'
        );
    }

    public function test_default_status_is_open_and_a_uuid_is_generated(): void
    {
        $this->assertSame(ReportStatus::Open, (new Report)->status);

        DB::table('reports')->insert($this->rawReport());

        $report = Report::firstOrFail();

        $this->assertSame(ReportStatus::Open, $report->status);
        $this->assertTrue(Str::isUuid($report->uuid));
        $this->assertNull($report->resolved_at);
    }

    public function test_an_open_report_has_no_resolution_data(): void
    {
        $this->assertRejected(
            fn () => DB::table('reports')->insert($this->rawReport(['resolved_at' => now()->toDateTimeString()])),
            'open report with a resolution date'
        );
        $this->assertRejected(
            fn () => DB::table('reports')->insert($this->rawReport(['resolved_by' => $this->moderator->id])),
            'open report with a resolver'
        );
    }

    public function test_a_closed_report_requires_a_resolution_date(): void
    {
        foreach (['resolved', 'dismissed'] as $status) {
            $this->assertRejected(
                fn () => DB::table('reports')->insert($this->rawReport(['status' => $status])),
                "{$status} without resolution date"
            );

            DB::table('reports')->insert($this->rawReport([
                'status' => $status,
                'resolved_at' => now()->toDateTimeString(),
                'resolved_by' => $this->moderator->id,
            ]));
        }

        DB::table('reports')->insert($this->rawReport([
            'status' => 'resolved',
            'resolved_at' => now()->toDateTimeString(),
        ]));

        $this->assertSame(3, Report::count());
    }

    public function test_resolved_and_dismissed_factory_states(): void
    {
        $resolved = Report::factory()->for($this->profile)->resolved()->create();
        $dismissed = Report::factory()->for($this->profile)->dismissed()->create();

        $this->assertSame(ReportStatus::Resolved, $resolved->fresh()->status);
        $this->assertSame(ReportStatus::Dismissed, $dismissed->fresh()->status);
        $this->assertNotNull($resolved->fresh()->resolved_at);
        $this->assertSame(UserRole::Moderator, $resolved->resolver->role);
    }

    public function test_open_scope_returns_only_open_reports(): void
    {
        Report::factory()->for($this->profile)->create();
        Report::factory()->for($this->profile)->resolved()->create();
        Report::factory()->for($this->profile)->dismissed()->create();

        $this->assertSame(1, Report::open()->count());
    }

    public function test_ownership_status_and_resolution_are_not_mass_assignable(): void
    {
        $report = new Report;

        foreach (['uuid', 'artisan_id', 'user_id', 'status', 'resolved_by', 'resolved_at'] as $field) {
            $this->assertFalse($report->isFillable($field), "{$field} must not be mass assignable");
        }

        foreach (['reason', 'description'] as $field) {
            $this->assertTrue($report->isFillable($field), "{$field} should be mass assignable");
        }
    }

    public function test_report_requires_an_existing_artisan(): void
    {
        $this->assertRejected(
            fn () => DB::table('reports')->insert($this->rawReport(['artisan_id' => 999999999])),
            'report without artisan'
        );
    }

    public function test_report_survives_the_permanent_deletion_of_its_reporter(): void
    {
        $report = Report::factory()->for($this->profile)->for($this->reporter, 'reporter')->create();

        $this->reporter->forceDelete();

        $this->assertNull($report->fresh()->user_id);
        $this->assertSame(ReportStatus::Open, $report->fresh()->status);
    }

    public function test_closed_report_survives_the_permanent_deletion_of_its_resolver(): void
    {
        $report = Report::factory()->for($this->profile)->resolved()->create();

        $report->resolver->forceDelete();

        $fresh = $report->fresh();

        $this->assertNull($fresh->resolved_by);
        $this->assertSame(ReportStatus::Resolved, $fresh->status);
        $this->assertNotNull($fresh->resolved_at);
    }

    public function test_permanently_deleting_an_artisan_removes_its_reports(): void
    {
        $report = Report::factory()->for($this->profile)->create();

        $this->profile->forceDelete();

        $this->assertDatabaseMissing('reports', ['id' => $report->id]);
    }

    public function test_soft_deleting_an_artisan_keeps_its_reports(): void
    {
        $report = Report::factory()->for($this->profile)->create();

        $this->profile->delete();

        $this->assertDatabaseHas('reports', ['id' => $report->id]);
    }
}
