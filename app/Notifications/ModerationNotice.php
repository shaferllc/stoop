<?php

namespace App\Notifications;

use App\Models\Dispatch;
use Illuminate\Notifications\Notification;

/**
 * Tells someone what moderators decided about their post, account or appeal. Moderators aren't named.
 */
class ModerationNotice extends Notification
{
    public function __construct(public string $message, public ?Dispatch $dispatch = null) {}

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
            'kind' => 'moderation',
            'message' => $this->message,
            'actor' => null,
            'actor_id' => 0,
            'dispatch_id' => $this->dispatch?->id,
            'thread_id' => 'moderation-'.now()->timestamp,
            'excerpt' => $this->dispatch ? str($this->dispatch->body)->limit(80)->toString() : null,
        ];
    }
}
