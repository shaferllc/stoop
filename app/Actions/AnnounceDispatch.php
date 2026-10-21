<?php

namespace App\Actions;

use App\Events\DispatchPublished;
use App\Models\Dispatch;
use App\Models\User;
use App\Notifications\StoopActivity;
use App\Support\Live;
use App\Support\Mentions;

/**
 * Notify the people a newly public dispatch involves. Runs once, when it goes public.
 */
class AnnounceDispatch
{
    public static function run(Dispatch $dispatch): void
    {
        if ($dispatch->status !== 'approved') {
            return;
        }

        if (! $dispatch->parent_id) {
            Live::broadcast(new DispatchPublished($dispatch->id));
        }

        $author = $dispatch->user;
        $notified = [$author->id];

        $tell = function (?User $user, string $kind) use ($author, $dispatch, &$notified) {
            if (! $user || in_array($user->id, $notified, true) || in_array($author->id, $user->hiddenUserIds(), true)) {
                return;
            }
            $notified[] = $user->id;
            $user->notify(new StoopActivity($kind, $author, $dispatch));
        };

        $tell($dispatch->parent?->user, 'reply');
        $tell($dispatch->quoted?->user, 'quote');

        $usernames = array_slice(Mentions::usernames($dispatch->body), 0, 10);
        if ($usernames) {
            User::whereIn('username', $usernames)->get()->each(fn (User $u) => $tell($u, 'mention'));
        }
    }
}
