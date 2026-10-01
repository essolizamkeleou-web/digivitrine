<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\SampleNotification;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_follows_the_native_laravel_notification_schema(): void
    {
        $this->assertTrue(Schema::hasColumns('notifications', [
            'id', 'type', 'notifiable_type', 'notifiable_id', 'data', 'read_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_user_receives_a_database_notification_with_title_and_body(): void
    {
        $user = User::factory()->create();

        $user->notify(new SampleNotification('Profil valide', 'Votre profil est en ligne.'));

        $this->assertSame(1, $user->notifications()->count());

        $notification = $user->notifications()->firstOrFail();

        $this->assertSame(SampleNotification::class, $notification->type);
        $this->assertEquals(['title' => 'Profil valide', 'body' => 'Votre profil est en ligne.'], $notification->data);
        $this->assertTrue($notification->notifiable->is($user));
    }

    public function test_notification_is_unread_until_marked_as_read(): void
    {
        $user = User::factory()->create();
        $user->notify(new SampleNotification('Titre', 'Corps'));

        $notification = $user->unreadNotifications()->firstOrFail();

        $this->assertNull($notification->read_at);
        $this->assertSame(1, $user->unreadNotifications()->count());

        $notification->markAsRead();

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertSame(0, $user->unreadNotifications()->count());
        $this->assertSame(1, $user->readNotifications()->count());
    }

    public function test_notification_identifiers_are_uuids(): void
    {
        $user = User::factory()->create();
        $user->notify(new SampleNotification('Titre', 'Corps'));

        $this->assertTrue(Str::isUuid($user->notifications()->firstOrFail()->id));
    }

    public function test_notifications_belong_to_their_recipient_only(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $first->notify(new SampleNotification('Pour le premier', 'Corps'));
        $first->notify(new SampleNotification('Encore le premier', 'Corps'));
        $second->notify(new SampleNotification('Pour le second', 'Corps'));

        $this->assertSame(2, $first->notifications()->count());
        $this->assertSame(1, $second->notifications()->count());
        $this->assertSame(3, DatabaseNotification::count());
    }

    public function test_soft_deleting_a_user_keeps_their_notifications(): void
    {
        $user = User::factory()->create();
        $user->notify(new SampleNotification('Titre', 'Corps'));

        $user->delete();

        $this->assertSame(1, DatabaseNotification::count());
    }
}
