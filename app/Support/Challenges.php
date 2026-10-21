<?php

namespace App\Support;

use App\Models\Challenge;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * List challenges: Been here check-ins at places on one of the magazine's lists, counted only
 * between the challenge's start and end (one per place). Reaching the goal records a finisher
 * once, which earns the challenge's badge and XP (see Achievements).
 */
class Challenges
{
    public const XP = 250;

    /**
     * First Been here per place on the list, per person, inside the window.
     */
    protected static function visits(Challenge $challenge): Builder
    {
        return DB::table('marks')
            ->join('dispatches', 'dispatches.id', '=', 'marks.dispatch_id')
            ->join('places', 'places.id', '=', 'dispatches.place_id')
            ->where('marks.kind', 'been')->where('places.list_name', $challenge->list_name)
            ->whereBetween('marks.created_at', [$challenge->starts_at, $challenge->ends_at])
            ->groupBy('marks.user_id', 'places.id')
            ->selectRaw('marks.user_id, places.id as place_id, min(marks.created_at) as been_at');
    }

    /**
     * @return array{count: int, goal: int, place_ids: array<int, int>, finished_at: ?string}
     */
    public static function progress(Challenge $challenge, ?User $user): array
    {
        $visits = $user ? self::visits($challenge)->where('marks.user_id', $user->id)->get()->sortBy('been_at')->values() : collect();

        return [
            'count' => $visits->count(),
            'goal' => $challenge->goal,
            'place_ids' => $visits->pluck('place_id')->map(fn ($id) => (int) $id)->all(),
            'finished_at' => $user ? DB::table('challenge_finishers')->where(['challenge_id' => $challenge->id, 'user_id' => $user->id])->value('finished_at') : null,
        ];
    }

    /**
     * Record anyone who has reached a goal on a running (or just-ended) challenge. Each
     * person finishes a challenge once. Returns the challenges this person just finished.
     *
     * @return Collection<int, Challenge>
     */
    public static function check(User $user): Collection
    {
        if ($user->isHouseAccount()) {
            return collect();
        }

        return Challenge::where('starts_at', '<=', now())->where('ends_at', '>=', now()->subDay())
            ->whereNotIn('id', DB::table('challenge_finishers')->where('user_id', $user->id)->select('challenge_id'))
            ->get()->filter(fn (Challenge $c) => self::record($c, $user->id));
    }

    /**
     * Check everyone with a check-in on a running challenge's list, for the hourly sweep
     * (check-ins also check right away).
     */
    public static function sweep(): int
    {
        $finished = 0;
        Challenge::where('starts_at', '<=', now())->where('ends_at', '>=', now()->subDay())->each(function (Challenge $c) use (&$finished) {
            $done = DB::table('challenge_finishers')->where('challenge_id', $c->id)->pluck('user_id');
            DB::query()->fromSub(self::visits($c), 'v')->whereNotIn('user_id', $done)->groupBy('user_id')
                ->havingRaw('count(*) >= ?', [$c->goal])->pluck('user_id')
                ->each(function ($userId) use ($c, &$finished) {
                    $user = User::find($userId);
                    $finished += $user && ! $user->isHouseAccount() && self::record($c, $user->id) ? 1 : 0;
                });
        });

        return $finished;
    }

    /**
     * Whether this call recorded the finish (false if short of the goal or already recorded).
     */
    protected static function record(Challenge $challenge, int $userId): bool
    {
        $visits = self::visits($challenge)->where('marks.user_id', $userId)->get()->sortBy('been_at')->values();

        if ($visits->count() < $challenge->goal) {
            return false;
        }

        return DB::table('challenge_finishers')->insertOrIgnore([
            'challenge_id' => $challenge->id,
            'user_id' => $userId,
            'finished_at' => $visits[$challenge->goal - 1]->been_at,
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }

    /**
     * The top players by places checked, ties to whoever got there first. Usernames only;
     * house accounts don't play.
     *
     * @return Collection<int, object{username: string, count: int}>
     */
    public static function leaderboard(Challenge $challenge, int $limit = 20): Collection
    {
        $rows = DB::query()->fromSub(self::visits($challenge), 'v')
            ->join('users', 'users.id', '=', 'v.user_id')
            ->where('users.is_bot', false)->where('users.username', '!=', config('stoop.magazine_account'))
            ->groupBy('users.id', 'users.username')
            ->selectRaw('users.username, count(*) as count, max(v.been_at) as last_at')
            ->orderByDesc('count')->orderBy('last_at')->take($limit)->get();

        return $rows->map(fn ($r) => (object) ['username' => $r->username, 'count' => (int) $r->count]);
    }

    /**
     * Who finished, first first, for staff sending prizes.
     *
     * @return Collection<int, object{username: string, name: string, email: string, finished_at: string}>
     */
    public static function finishers(Challenge $challenge): Collection
    {
        return DB::table('challenge_finishers')->join('users', 'users.id', '=', 'challenge_finishers.user_id')
            ->where('challenge_id', $challenge->id)->orderBy('finished_at')
            ->get(['users.username', 'users.name', 'users.email', 'challenge_finishers.finished_at']);
    }

    /**
     * The finishers as CSV rows. Cells that a spreadsheet would run as a formula are quoted
     * with a leading apostrophe.
     *
     * @return array<int, array<int, string>>
     */
    public static function csv(Challenge $challenge): array
    {
        $safe = fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'".$v : (string) $v;

        return [
            ['username', 'name', 'email', 'finished_at'],
            ...self::finishers($challenge)->map(fn ($f) => array_map($safe, [$f->username, $f->name, $f->email, $f->finished_at]))->all(),
        ];
    }

    /**
     * Challenge badges someone has earned, keyed like Achievements::BADGES.
     *
     * @return array<string, array{name: string, description: string}>
     */
    public static function badges(User $user): array
    {
        return DB::table('challenge_finishers')->join('challenges', 'challenges.id', '=', 'challenge_finishers.challenge_id')
            ->where('challenge_finishers.user_id', $user->id)->orderBy('finished_at')
            ->get(['challenges.id', 'challenges.badge_name', 'challenges.title'])
            ->mapWithKeys(fn ($c) => ['challenge-'.$c->id => ['name' => $c->badge_name, 'description' => 'Finished the challenge “'.$c->title.'”']])
            ->all();
    }
}
