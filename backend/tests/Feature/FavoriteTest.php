<?php

namespace Tests\Feature;

use App\Models\Artisan;
use App\Models\Favorite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AssertsDatabaseRejections;
use Tests\TestCase;

class FavoriteTest extends TestCase
{
    use AssertsDatabaseRejections;
    use RefreshDatabase;

    private Artisan $profile;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->profile = Artisan::factory()->create();
        $this->user = User::factory()->create();
    }

    public function test_user_can_save_an_artisan_as_favorite(): void
    {
        $this->user->favoriteArtisans()->attach($this->profile->id);

        $this->assertTrue($this->user->favoriteArtisans->first()->is($this->profile));
        $this->assertTrue($this->profile->favoritedBy->first()->is($this->user));
        $this->assertSame(1, Favorite::count());
    }

    public function test_favorite_model_exposes_its_user_and_artisan(): void
    {
        $favorite = Favorite::factory()->for($this->user)->for($this->profile)->create();

        $this->assertTrue($favorite->user->is($this->user));
        $this->assertTrue($favorite->artisan->is($this->profile));
        $this->assertNotNull($favorite->created_at);
    }

    public function test_the_same_artisan_cannot_be_saved_twice_by_the_same_user(): void
    {
        $this->user->favoriteArtisans()->attach($this->profile->id);

        $this->assertRejected(
            fn () => $this->user->favoriteArtisans()->attach($this->profile->id),
            'duplicate favorite through the relation'
        );
        $this->assertRejected(
            fn () => Favorite::factory()->for($this->user)->for($this->profile)->create(),
            'duplicate favorite through the factory'
        );

        $this->assertSame(1, Favorite::count());
    }

    public function test_an_artisan_can_be_saved_by_several_users_and_a_user_can_save_several_artisans(): void
    {
        $otherUser = User::factory()->create();
        $otherProfile = Artisan::factory()->create();

        $this->user->favoriteArtisans()->attach([$this->profile->id, $otherProfile->id]);
        $otherUser->favoriteArtisans()->attach($this->profile->id);

        $this->assertSame(2, $this->user->favoriteArtisans()->count());
        $this->assertSame(2, $this->profile->favoritedBy()->count());
    }

    public function test_a_favorite_can_be_removed(): void
    {
        $favorite = Favorite::factory()->for($this->user)->for($this->profile)->create();

        $favorite->delete();

        $this->assertModelMissing($favorite);

        $this->user->favoriteArtisans()->attach($this->profile->id);
        $this->user->favoriteArtisans()->detach($this->profile->id);

        $this->assertSame(0, Favorite::count());
    }

    public function test_favorite_requires_an_existing_user_and_artisan(): void
    {
        $this->assertRejected(
            fn () => DB::table('favorites')->insert(['user_id' => 999999999, 'artisan_id' => $this->profile->id]),
            'favorite without user'
        );
        $this->assertRejected(
            fn () => DB::table('favorites')->insert(['user_id' => $this->user->id, 'artisan_id' => 999999999]),
            'favorite without artisan'
        );
    }

    public function test_nothing_is_mass_assignable(): void
    {
        $favorite = new Favorite;

        $this->assertFalse($favorite->isFillable('user_id'));
        $this->assertFalse($favorite->isFillable('artisan_id'));
    }

    public function test_permanently_deleting_an_artisan_removes_its_favorites(): void
    {
        $favorite = Favorite::factory()->for($this->user)->for($this->profile)->create();

        $this->profile->forceDelete();

        $this->assertModelMissing($favorite);
    }

    public function test_permanently_deleting_a_user_removes_their_favorites(): void
    {
        $favorite = Favorite::factory()->for($this->user)->for($this->profile)->create();

        $this->user->forceDelete();

        $this->assertModelMissing($favorite);
    }

    public function test_soft_deleting_an_artisan_keeps_the_favorite_rows(): void
    {
        $favorite = Favorite::factory()->for($this->user)->for($this->profile)->create();

        $this->profile->delete();

        $this->assertModelExists($favorite);
    }
}
