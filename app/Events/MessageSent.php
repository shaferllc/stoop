<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Tells everyone with the conversation open that a new message arrived. Carries no message text.
 */
class MessageSent implements ShouldBroadcastNow
{
    public function __construct(public int $conversationId, public int $messageId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('conversation.'.$this->conversationId);
    }

    public function broadcastAs(): string
    {
        return 'message';
    }
}
