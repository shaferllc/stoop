<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Lets open feeds update their "N new dispatches" button right away.
 */
class DispatchPublished implements ShouldBroadcastNow
{
    public function __construct(public int $dispatchId) {}

    public function broadcastOn(): Channel
    {
        return new Channel('feed');
    }

    public function broadcastAs(): string
    {
        return 'published';
    }
}
