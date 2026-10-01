<?php

namespace Tests\Feature;

use App\Enums\ReviewStatus;
use App\Models\Artisan;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\AssertsDatabaseRejections;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use AssertsDatabaseRejections;
    use RefreshDatabase;

    private Artisan $profile;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        $this->profile = Artisan::factory()->create();
        $this->author = User::factory()->create();
    }

    /**
     * Minimal valid row for raw inserts (bypasses the model on purpose).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rawReview(array $overrides = []): array
    {
        return array_merge([
            'artisan_id' => $this->profile->id,
            'user_id' => $this->author->id,
            'rating' => 4,
        ], $overrides);
    }

    public function test_user_can_review_an_artisan(): void
    {
        $review = Review::factory()->for($this->profile)->for($this->author)->create();

        $this->assertTrue($review->artisan->is($this->profile));
        $this->assertTrue($review->user->is($this->author));
        $this->assertTrue($this->profile->reviews->first()->is($review));
        $this->assertTrue($this->author->reviews->first()->is($review));
    }

    public function test_rating_accepts_values_from_one_to_five(): void
    {
        foreach (range(1, 5) as $rating) {
            Review::factory()->for($this->profile)->for(User::factory()->create())->create(['rating' => $rating]);
        }

        $this->assertSame(5, $this->profile->reviews()->count());
        $this->assertEquals([1, 2, 3, 4, 5], $this->profile->reviews()->orderBy('rating')->pluck('rating')->all());
    }

    public function test_database_rejects_an_invalid_rating(): void
    {
        foreach ([0, 6, -1, 100] as $rating) {
            $this->assertRejected(
                fn () => DB::table('reviews')->insert($this->rawReview(['rating' => $rating])),
                "rating {$rating}"
            );
        }

        $this->assertRejected(
            fn () => DB::table('reviews')->insert($this->rawReview(['rating' => null])),
            'missing rating'
        );
    }

    public function test_comment_is_optional_but_cannot_be_blank(): void
    {
        $this->assertRejected(
            fn () => DB::table('reviews')->insert($this->rawReview(['comment' => '   '])),
            'blank comment'
        );

        DB::table('reviews')->insert($this->rawReview(['comment' => null]));

        $this->assertNull(Review::firstOrFail()->comment);
    }

    public function test_default_status_is_pending_and_a_uuid_is_generated(): void
    {
        $this->assertSame(ReviewStatus::Pending, (new Review)->status);

        DB::table('reviews')->insert($this->rawReview());

        $review = Review::firstOrFail();

        $this->assertSame(ReviewStatus::Pending, $review->status);
        $this->assertTrue(Str::isUuid($review->uuid));
    }

    public function test_status_is_limited_to_the_known_values(): void
    {
        $this->assertRejected(
            fn () => DB::table('reviews')->insert($this->rawReview(['status' => 'archived'])),
            'unknown status'
        );

        foreach (ReviewStatus::cases() as $status) {
            $user = User::factory()->create();
            Review::factory()->for($this->profile)->for($user)->create(['status' => $status]);

            $this->assertSame($status, Review::where('user_id', $user->id)->firstOrFail()->status);
        }
    }

    public function test_a_user_can_review_an_artisan_only_once(): void
    {
        Review::factory()->for($this->profile)->for($this->author)->create();

        $this->assertRejected(
            fn () => Review::factory()->for($this->profile)->for($this->author)->create(),
            'second review of the same artisan by the same user'
        );

        Review::factory()->for(Artisan::factory()->create())->for($this->author)->create();
        Review::factory()->for($this->profile)->for(User::factory()->create())->create();

        $this->assertSame(3, Review::count());
    }

    public function test_anonymised_reviews_do_not_block_each_other(): void
    {
        DB::table('reviews')->insert($this->rawReview(['user_id' => null]));
        DB::table('reviews')->insert($this->rawReview(['user_id' => null]));

        $this->assertSame(2, Review::whereNull('user_id')->count());
    }

    public function test_published_scope_returns_only_published_reviews(): void
    {
        foreach (ReviewStatus::cases() as $status) {
            Review::factory()->for($this->profile)->for(User::factory()->create())->create(['status' => $status]);
        }

        $published = Review::published()->get();

        $this->assertCount(1, $published);
        $this->assertSame(ReviewStatus::Published, $published->first()->status);
    }

    public function test_ownership_and_status_are_not_mass_assignable(): void
    {
        $review = new Review;

        foreach (['uuid', 'artisan_id', 'user_id', 'status'] as $field) {
            $this->assertFalse($review->isFillable($field), "{$field} must not be mass assignable");
        }

        foreach (['rating', 'comment'] as $field) {
            $this->assertTrue($review->isFillable($field), "{$field} should be mass assignable");
        }
    }

    public function test_review_requires_an_existing_artisan(): void
    {
        $this->assertRejected(
            fn () => DB::table('reviews')->insert($this->rawReview(['artisan_id' => 999999999])),
            'review without artisan'
        );
    }

    public function test_review_survives_the_permanent_deletion_of_its_author(): void
    {
        $review = Review::factory()->for($this->profile)->for($this->author)->create();

        $this->author->forceDelete();

        $this->assertNotNull($review->fresh());
        $this->assertNull($review->fresh()->user_id);
        $this->assertNull($review->fresh()->user);
    }

    public function test_permanently_deleting_an_artisan_removes_its_reviews(): void
    {
        $review = Review::factory()->for($this->profile)->create();

        $this->profile->forceDelete();

        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
    }

    public function test_soft_deleting_an_artisan_keeps_its_reviews(): void
    {
        $review = Review::factory()->for($this->profile)->create();

        $this->profile->delete();

        $this->assertDatabaseHas('reviews', ['id' => $review->id]);
    }
}
