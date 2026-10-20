<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * People worth following: first those followed by people you follow, then the most-followed.
 */
class Suggestions
{
    /**
     * @return Collection<int, User>
     */
    public static function for(User $user, int $limit = 3): Collection
    {
        $exclude = [$user->id, ...$user->following()->pluck('users.id'), ...$user->hiddenUserIds()];
        $myFollowing = $user->following()->select('users.id');

        $friendsOfFriends = DB::table('follows')
            ->whereIn('follower_id', $myFollowing)
            ->whereNotIn('followee_id', $exclude)
            ->groupBy('followee_id')
            ->selectRaw('followee_id, count(*) as mutuals')
            ->orderByDesc('mutuals')->limit($limit)->pluck('mutuals', 'followee_id');

        $people = User::whereIn('id', $friendsOfFriends->keys())->withCount('followers')->get()
            ->each(fn ($u) => $u->reason = $friendsOfFriends[$u->id].' '.str('person')->plural($friendsOfFriends[$u->id]).' you follow '.($friendsOfFriends[$u->id] === 1 ? 'follows' : 'follow').' them')
            ->sortByDesc(fn ($u) => $friendsOfFriends[$u->id]);

        if ($people->count() < $limit) {
            $popular = User::whereNotIn('id', [...$exclude, ...$people->pluck('id')])
                ->withCount('followers')->orderByDesc('followers_count')->limit($limit - $people->count())->get()
                ->each(fn ($u) => $u->reason = $u->followers_count.' '.str('follower')->plural($u->followers_count));
            $people = $people->concat($popular);
        }

        return $people->values();
    }
}
