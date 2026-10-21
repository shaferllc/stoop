<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * @welcome's one-time hello: a few tips for your stops and people nearby to follow.
 */
class Welcome extends Notification
{
    /**
     * @param  list<string>  $tips
     * @param  list<string>  $people  usernames
     */
    public function __construct(public User $bot, public array $tips, public array $people) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'welcome',
            'actor' => $this->bot->username,
            'actor_id' => $this->bot->id,
            'message' => 'Welcome to Stoop! '.implode(' ', $this->tips).($this->people ? ' Neighbors to follow: @'.implode(', @', $this->people).'.' : ''),
            'tips' => $this->tips,
            'people' => $this->people,
            'dispatch_id' => null,
            'thread_id' => 'welcome',
            'excerpt' => null,
        ];
    }
}
