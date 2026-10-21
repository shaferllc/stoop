<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Nudges a person's open tabs to refresh their unread counts.
 */
class UserNotified implements ShouldBroadcastNow
{
    public function __construct(public int $userId, public string $kind) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'notified';
    }
}
