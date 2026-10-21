<?php

namespace App\Notifications;

use App\Models\Dispatch;
use App\Models\User;

/**
 * In-app notices from the magazine: a new article by a writer you follow ("author"), or an
 * editor asking to quote your dispatch in a story ("quote_request").
 */
class MagazineActivity extends StoopActivity
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(string $kind, User $actor, ?Dispatch $dispatch = null, public array $extra = [])
    {
        parent::__construct($kind, $actor, $dispatch);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [...parent::toArray($notifiable), ...$this->extra];
    }
}
