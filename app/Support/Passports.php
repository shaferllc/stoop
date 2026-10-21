<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The magazine's lists as passports: tap "Been here" at 10 places on a list (or all of
 * them, if the list is shorter) to earn its stamp.
 */
class Passports
{
    public const STAMP_AT = 10;

    /**
     * Places on each list someone has been to, with the time they reached each one.
     *
     * @return Collection<string, Collection<int, object{place_id: int, been_at: string}>>
     */
    protected static function visits(User $user): Collection
    {
        return DB::table('marks')
            ->join('dispatches', 'dispatches.id', '=', 'marks.dispatch_id')
            ->join('places', 'places.id', '=', 'dispatches.place_id')
            ->where('marks.user_id', $user->id)->where('marks.kind', 'been')->whereNotNull('places.list_name')
            ->groupBy('places.list_name', 'places.id')
            ->selectRaw('places.list_name, places.id as place_id, min(marks.created_at) as been_at')
            ->get()->groupBy('list_name');
    }

    /**
     * Every list with this person's progress.
     *
     * @return Collection<int, array{list: string, size: int, needed: int, been: int, stamped_at: ?string}>
     */
    public static function for(User $user): Collection
    {
        $visits = self::visits($user);

        return DB::table('places')->whereNotNull('list_name')->groupBy('list_name')
            ->selectRaw('list_name, count(*) as size')->orderBy('list_name')->get()
            ->map(function ($list) use ($visits) {
                $needed = min(self::STAMP_AT, $list->size);
                $mine = ($visits[$list->list_name] ?? collect())->sortBy('been_at')->values();

                return [
                    'list' => $list->list_name,
                    'size' => (int) $list->size,
                    'needed' => $needed,
                    'been' => $mine->count(),
                    'stamped_at' => $mine->count() >= $needed ? $mine[$needed - 1]->been_at : null,
                ];
            });
    }

    /**
     * Scavenger hunt stamps: one for every step found, newest first.
     *
     * @return Collection<int, object{hunt: string, slug: string, position: int, stamped_at: string}>
     */
    public static function huntStamps(User $user): Collection
    {
        return DB::table('hunt_completions')
            ->join('hunt_steps', 'hunt_steps.id', '=', 'hunt_completions.hunt_step_id')
            ->join('hunts', 'hunts.id', '=', 'hunt_steps.hunt_id')
            ->where('hunt_completions.user_id', $user->id)
            ->orderByDesc('hunt_completions.created_at')
            ->get(['hunts.name as hunt', 'hunts.slug', 'hunt_steps.position', 'hunt_completions.created_at as stamped_at']);
    }

    /**
     * Everyone who has earned a stamp, newest first, for the magazine's shout-outs.
     *
     * @return Collection<int, array{user: User, list: string, stamped_at: string}>
     */
    public static function recentStamps(int $limit = 50): Collection
    {
        $userIds = DB::table('marks')->join('dispatches', 'dispatches.id', '=', 'marks.dispatch_id')
            ->join('places', 'places.id', '=', 'dispatches.place_id')
            ->where('marks.kind', 'been')->whereNotNull('places.list_name')->distinct()->pluck('marks.user_id');

        return User::whereIn('id', $userIds)->get()
            ->flatMap(fn (User $u) => self::for($u)->whereNotNull('stamped_at')->map(fn ($p) => ['user' => $u, 'list' => $p['list'], 'stamped_at' => $p['stamped_at']]))
            ->sortByDesc('stamped_at')->take($limit)->values();
    }
}
