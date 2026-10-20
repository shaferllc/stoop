<?php

namespace App\Notifications;

use App\Models\Dispatch;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * In-app notice that someone replied to, quoted, or followed you.
 */
class StoopActivity extends Notification
{
    public function __construct(
        public string $kind,
        public User $actor,
        public ?Dispatch $dispatch = null,
    ) {}

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
            'kind' => $this->kind,
            'actor_id' => $this->actor->id,
            'actor' => $this->actor->username,
            'dispatch_id' => $this->dispatch?->id,
            'excerpt' => $this->dispatch ? str($this->dispatch->body)->limit(80)->toString() : null,
        ];
    }
}
