<?php

namespace Tests\Support;

use Illuminate\Notifications\Notification;

/**
 * Minimal database notification used to exercise Laravel's native notifications table.
 */
class SampleNotification extends Notification
{
    public function __construct(private string $title, private string $body) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'body' => $this->body];
    }
}
