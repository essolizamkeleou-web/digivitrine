<?php

namespace Tests\Unit;

use App\Enums\ArtisanStatus;
use PHPUnit\Framework\TestCase;

class ArtisanStatusTest extends TestCase
{
    public function test_values_match_the_database_check_constraint(): void
    {
        $this->assertSame(
            ['draft', 'pending', 'validated', 'rejected', 'suspended'],
            array_map(fn (ArtisanStatus $status) => $status->value, ArtisanStatus::cases())
        );
    }

    public function test_only_validated_profiles_are_publicly_visible(): void
    {
        foreach (ArtisanStatus::cases() as $status) {
            $this->assertSame($status === ArtisanStatus::Validated, $status->isPubliclyVisible());
        }
    }

    public function test_lifecycle_transitions_follow_the_business_rules(): void
    {
        $this->assertTrue(ArtisanStatus::Draft->canTransitionTo(ArtisanStatus::Pending));
        $this->assertTrue(ArtisanStatus::Pending->canTransitionTo(ArtisanStatus::Validated));
        $this->assertTrue(ArtisanStatus::Pending->canTransitionTo(ArtisanStatus::Rejected));
        $this->assertTrue(ArtisanStatus::Rejected->canTransitionTo(ArtisanStatus::Pending));
        $this->assertTrue(ArtisanStatus::Validated->canTransitionTo(ArtisanStatus::Suspended));
        $this->assertTrue(ArtisanStatus::Validated->canTransitionTo(ArtisanStatus::Pending));
        $this->assertTrue(ArtisanStatus::Suspended->canTransitionTo(ArtisanStatus::Validated));
    }

    public function test_forbidden_transitions_are_refused(): void
    {
        $this->assertFalse(ArtisanStatus::Draft->canTransitionTo(ArtisanStatus::Validated));
        $this->assertFalse(ArtisanStatus::Pending->canTransitionTo(ArtisanStatus::Draft));
        $this->assertFalse(ArtisanStatus::Rejected->canTransitionTo(ArtisanStatus::Validated));
        $this->assertFalse(ArtisanStatus::Validated->canTransitionTo(ArtisanStatus::Rejected));
        $this->assertFalse(ArtisanStatus::Suspended->canTransitionTo(ArtisanStatus::Pending));
    }

    public function test_a_status_cannot_transition_to_itself(): void
    {
        foreach (ArtisanStatus::cases() as $status) {
            $this->assertFalse($status->canTransitionTo($status));
        }
    }
}
