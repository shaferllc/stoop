<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Badges and XP, worked out from what someone has actually done. Nothing is stored, so
 * removing activity can lock a badge again.
 */
class Achievements
{
    public const BADGES = [
        'doors-open' => ['name' => 'Doors Open', 'description' => 'Filed your first dispatch'],
        'neighbor' => ['name' => 'Neighbor', 'description' => 'Replied to 5 other people'],
        'regular' => ['name' => 'Regular', 'description' => 'Posted 7 days in a row'],
        'local' => ['name' => 'Local', 'description' => 'Posted 30 days in a row'],
        'lifer' => ['name' => 'Lifer', 'description' => 'Posted 100 days in a row'],
        'all-lines' => ['name' => 'All Lines', 'description' => 'Posted on every Metro line'],
        'station-hopper' => ['name' => 'Station Hopper', 'description' => 'Tagged 10 different stations'],
        'critic' => ['name' => 'Critic', 'description' => 'Filed 10 Been here posts'],
        'pollster' => ['name' => 'Pollster', 'description' => 'Ran 3 polls'],
        'big-night' => ['name' => 'Big Night Out', 'description' => 'Posted a Happening 10 people went to'],
        'talk-of-the-town' => ['name' => 'Talk of the Town', 'description' => 'Got 25 replies on one dispatch'],
        'half-smoke-hall' => ['name' => 'Half-Smoke Hall of Fame', 'description' => 'Received 25 half-smokes'],
        'line-red' => ['name' => 'Rode the Red Line', 'description' => 'Posted from every Red Line station'],
        'line-orange' => ['name' => 'Rode the Orange Line', 'description' => 'Posted from every Orange Line station'],
        'line-blue' => ['name' => 'Rode the Blue Line', 'description' => 'Posted from every Blue Line station'],
        'line-green' => ['name' => 'Rode the Green Line', 'description' => 'Posted from every Green Line station'],
        'line-yellow' => ['name' => 'Rode the Yellow Line', 'description' => 'Posted from every Yellow Line station'],
        'line-silver' => ['name' => 'Rode the Silver Line', 'description' => 'Posted from every Silver Line station'],
        'whole-system' => ['name' => 'The Whole System', 'description' => 'Posted from all 98 Metro stations'],
        'crossed-the-river' => ['name' => 'Crossed the River', 'description' => 'Posted from a station east of the Anacostia'],
        'tri-state' => ['name' => 'DMV', 'description' => 'Posted from stations in DC, Maryland and Virginia'],
        'mayor' => ['name' => 'Mayor', 'description' => 'The most helpful person at a stop this month'],
    ];

    // Like /dev/null's Unix ranks, named for how far into the city you are.
    public const RANKS = [0 => 'Tourist', 100 => 'Commuter', 500 => 'Regular', 1500 => 'Local', 4000 => 'Block Captain', 10000 => 'Mayor'];

    /**
     * @return array<string, array{name: string, description: string}>
     */
    public static function unlocked(User $user): array
    {
        $s = self::stats($user);

        $earned = [
            'doors-open' => $s['dispatches'] >= 1,
            'neighbor' => $s['replies_to_others'] >= 5,
            'regular' => $s['longest_streak'] >= 7,
            'local' => $s['longest_streak'] >= 30,
            'lifer' => $s['longest_streak'] >= 100,
            'all-lines' => $s['lines'] >= count(config('stoop.lines')),
            'station-hopper' => $s['stations'] >= 10,
            'critic' => $s['been_posts'] >= 10,
            'pollster' => $s['polls'] >= 3,
            'big-night' => $s['best_happening'] >= 10,
            'talk-of-the-town' => $s['most_replies'] >= 25,
            'half-smoke-hall' => $s['half_smokes_received'] >= 25,
            'whole-system' => count($s['visited']) >= count(config('metro.stations')),
            'crossed-the-river' => collect($s['visited'])->contains(fn ($code) => Stations::eastOfTheRiver($code)),
            'tri-state' => count(array_unique(array_map(fn ($code) => Stations::jurisdiction($code), $s['visited']))) >= 3,
            'mayor' => Stations::mayorships($user) !== [],
        ];

        foreach (config('metro.lines') as $line => $stops) {
            $earned["line-$line"] = array_diff($stops, $s['visited']) === [];
        }

        return array_intersect_key(self::BADGES, array_filter($earned));
    }

    /**
     * 10 per dispatch, 15 per day of the longest streak, 5 per Been here/Going/Save received,
     * 5 per reply to someone else, and 2 per mark given to someone else.
     */
    public static function xp(User $user): int
    {
        $s = self::stats($user);

        return $s['dispatches'] * 10 + $s['longest_streak'] * 15 + $s['marks_received'] * 5
            + $s['replies_to_others'] * 5 + $s['marks_given'] * 2;
    }

    public static function rank(int $xp): string
    {
        return collect(self::RANKS)->filter(fn ($name, $min) => $xp >= $min)->last();
    }

    /**
     * @return array{current: int, longest: int}
     */
    public static function streaks(User $user): array
    {
        $days = self::activeDays($user);
        $longest = $run = 0;
        $previous = null;

        foreach ($days as $day) {
            $run = $previous && Carbon::parse($previous)->addDay()->toDateString() === $day ? $run + 1 : 1;
            $longest = max($longest, $run);
            $previous = $day;
        }

        $current = 0;
        $today = now(config('stoop.timezone'))->startOfDay();
        $cursor = in_array($today->toDateString(), $days, true) ? $today : $today->subDay();
        while (in_array($cursor->toDateString(), $days, true)) {
            $current++;
            $cursor = $cursor->subDay();
        }

        return ['current' => $current, 'longest' => $longest];
    }

    /**
     * Dispatch counts per DC calendar day, for the ridership graph.
     *
     * @return array<string, int>
     */
    public static function dailyCounts(User $user, int $days = 371): array
    {
        return $user->dispatches()->where('status', 'approved')
            ->where('created_at', '>=', now()->subDays($days))
            ->pluck('created_at')
            ->countBy(fn ($at) => $at->timezone(config('stoop.timezone'))->toDateString())
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected static function activeDays(User $user): array
    {
        return $user->dispatches()->where('status', 'approved')->pluck('created_at')
            ->map(fn ($at) => $at->timezone(config('stoop.timezone'))->toDateString())
            ->unique()->sort()->values()->all();
    }

    /**
     * @return array<string, int>
     */
    protected static function stats(User $user): array
    {
        return once(function () use ($user) {
            $approved = $user->dispatches()->where('status', 'approved');
            $mine = (clone $approved)->select('id');

            return [
                'dispatches' => (clone $approved)->count(),
                'replies_to_others' => (clone $approved)->whereNotNull('parent_id')
                    ->whereHas('parent', fn ($q) => $q->where('user_id', '!=', $user->id))->count(),
                'longest_streak' => self::streaks($user)['longest'],
                'lines' => (clone $approved)->whereNull('parent_id')->distinct()->count('line'),
                'stations' => (clone $approved)->whereNotNull('stop')->distinct()->count('stop'),
                'been_posts' => (clone $approved)->where('type', 'been')->count(),
                'polls' => (clone $approved)->where('type', 'poll')->count(),
                'best_happening' => (int) DB::table('marks')->where('kind', 'going')
                    ->whereIn('dispatch_id', (clone $approved)->where('type', 'happening')->select('id'))
                    ->where('user_id', '!=', $user->id)
                    ->groupBy('dispatch_id')->selectRaw('count(*) as c')->orderByDesc('c')->value('c'),
                'most_replies' => (int) DB::table('dispatches')->whereIn('parent_id', $mine)->where('status', 'approved')
                    ->groupBy('parent_id')->selectRaw('count(*) as c')->orderByDesc('c')->value('c'),
                'half_smokes_received' => DB::table('half_smokes')->whereIn('dispatch_id', $mine)->count(),
                'visited' => Stations::visited($user),
                'marks_received' => DB::table('marks')->whereIn('dispatch_id', $mine)->where('user_id', '!=', $user->id)->count(),
                'marks_given' => DB::table('marks')->where('user_id', $user->id)
                    ->whereNotIn('dispatch_id', $mine)->count(),
            ];
        });
    }
}
