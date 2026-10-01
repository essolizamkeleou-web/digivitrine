<?php

namespace Tests\Unit;

use App\Enums\ProfileEventType;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\ReviewStatus;
use PHPUnit\Framework\TestCase;

class InteractionEnumsTest extends TestCase
{
    public function test_review_status_values_match_the_database_check_constraint(): void
    {
        $this->assertSame(
            ['pending', 'published', 'hidden'],
            array_map(fn (ReviewStatus $case) => $case->value, ReviewStatus::cases())
        );
    }

    public function test_only_published_reviews_are_publicly_visible(): void
    {
        foreach (ReviewStatus::cases() as $status) {
            $this->assertSame($status === ReviewStatus::Published, $status->isPubliclyVisible());
        }
    }

    public function test_report_reason_values_match_the_database_check_constraint(): void
    {
        $this->assertSame(
            ['false_information', 'inappropriate_content', 'duplicate_profile', 'wrong_contact', 'other'],
            array_map(fn (ReportReason $case) => $case->value, ReportReason::cases())
        );
    }

    public function test_report_status_values_and_closed_state(): void
    {
        $this->assertSame(
            ['open', 'resolved', 'dismissed'],
            array_map(fn (ReportStatus $case) => $case->value, ReportStatus::cases())
        );

        $this->assertFalse(ReportStatus::Open->isClosed());
        $this->assertTrue(ReportStatus::Resolved->isClosed());
        $this->assertTrue(ReportStatus::Dismissed->isClosed());
    }

    public function test_profile_event_type_values_match_the_database_check_constraint(): void
    {
        $this->assertSame(
            ['profile_view', 'phone_click', 'whatsapp_click', 'share_click', 'direction_click', 'favorite_add'],
            array_map(fn (ProfileEventType $case) => $case->value, ProfileEventType::cases())
        );
    }
}
