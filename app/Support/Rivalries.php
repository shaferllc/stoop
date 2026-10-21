<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Weekly neighborhood matchups and line standings, scored on helpfulness (the same
 * points that decide stop mayors). Weeks run Monday to Sunday, DC time.
 */
class Rivalries
{
    public const PER_WEEK = 3;

    // Neighboring stops with a natural rivalry.
    public const PAIRS = [
        ['E04', 'E05', 'Columbia Heights vs. Petworth'],
        ['F05', 'F04', 'Navy Yard vs. the Wharf'],
        ['E03', 'E02', 'U Street vs. Shaw'],
        ['D06', 'D05', 'Eastern Market vs. Capitol South'],
        ['K02', 'K04', 'Clarendon vs. Ballston'],
        ['A04', 'A05', 'Woodley Park vs. Cleveland Park'],
        ['B35', 'B03', 'NoMa vs. Union Station'],
        ['C13', 'C12', 'Old Town vs. Braddock Road'],
        ['B05', 'B07', 'Brookland vs. Takoma'],
        ['F06', 'F07', 'Anacostia vs. Congress Heights'],
        ['B08', 'A09', 'Silver Spring vs. Bethesda'],
        ['A03', 'C04', 'Dupont vs. Foggy Bottom'],
    ];

    public static function weekStart(?CarbonImmutable $at = null): CarbonImmutable
    {
        return ($at ?? now(config('stoop.timezone'))->toImmutable())->startOfWeek(CarbonImmutable::MONDAY);
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    public static function pairsFor(CarbonImmutable $weekStart): array
    {
        $week = (int) floor(CarbonImmutable::parse('2026-01-05')->diffInWeeks($weekStart));
        $pairs = [];
        for ($i = 0; $i < self::PER_WEEK; $i++) {
            $pairs[] = self::PAIRS[(($week * self::PER_WEEK) + $i) % count(self::PAIRS)];
        }

        return $pairs;
    }

    /**
     * @return Collection<int, array{title: string, stops: array<string, int>, leader: ?string}>
     */
    public static function matchups(CarbonImmutable $weekStart): Collection
    {
        $pairs = self::pairsFor($weekStart);
        $key = 'stoop:rivalries:'.$weekStart->toDateString();
        $finished = $weekStart->addWeek()->isPast();

        $compute = function () use ($pairs, $weekStart) {
            $stops = collect($pairs)->flatMap(fn ($p) => [$p[0], $p[1]])->all();
            $points = Stations::points($weekStart->utc(), $weekStart->addWeek()->utc(), $stops)->groupBy('stop')->map->sum('points');

            return collect($pairs)->map(function ($pair) use ($points) {
                $scores = [$pair[0] => (int) ($points[$pair[0]] ?? 0), $pair[1] => (int) ($points[$pair[1]] ?? 0)];
                $leader = $scores[$pair[0]] === $scores[$pair[1]] ? null : array_search(max($scores), $scores, true);

                return ['title' => $pair[2], 'stops' => $scores, 'leader' => $leader];
            })->all();
        };

        // Finished weeks never change, so they're cached for good; this week for 5 minutes.
        // The cache only holds plain arrays (it won't rebuild objects), so wrap after reading.
        return collect($finished ? Cache::rememberForever($key, $compute) : Cache::remember($key, now()->addMinutes(5), $compute));
    }

    /**
     * The matchup a stop won last week, if it won one.
     */
    public static function crownFor(string $stop): ?array
    {
        return self::matchups(self::weekStart()->subWeek())->first(fn ($m) => $m['leader'] === $stop);
    }

    /**
     * This week's points for each line's stops, and how many people earned them.
     *
     * @return Collection<string, array{points: int, people: int}>
     */
    public static function lineStandings(?CarbonImmutable $weekStart = null): Collection
    {
        $weekStart ??= self::weekStart();

        return collect(Cache::remember('stoop:lines:'.$weekStart->toDateString(), now()->addMinutes(5), function () use ($weekStart) {
            $rows = Stations::points($weekStart->utc(), $weekStart->addWeek()->utc());

            return collect(config('metro.lines'))->map(function ($stops) use ($rows) {
                $mine = $rows->whereIn('stop', $stops);

                return ['points' => (int) $mine->sum('points'), 'people' => $mine->pluck('user_id')->unique()->count()];
            })->sortByDesc('points')->all();
        }));
    }

    /** Helpfulness points at a line's stops in a month that make someone one of its regulars. */
    public const CUP_REGULAR = 5;

    public static function monthStart(?CarbonImmutable $at = null): CarbonImmutable
    {
        return ($at ?? now(config('stoop.timezone'))->toImmutable())->startOfMonth();
    }

    /**
     * The monthly Line Cup: each line scores the helpfulness points its riders earned at its
     * stops that month (the same points as the weekly standings). Regulars are the people
     * who earned at least CUP_REGULAR of them; the winning line's regulars get the crown.
     *
     * @return Collection<string, array{points: int, people: int, regulars: array<int, int>}>
     */
    public static function lineCup(CarbonImmutable $monthStart): Collection
    {
        $key = 'stoop:line-cup:'.$monthStart->format('Y-m');
        $compute = function () use ($monthStart) {
            $rows = Stations::points($monthStart->utc(), $monthStart->addMonthNoOverflow()->utc());

            return collect(config('metro.lines'))->map(function ($stops) use ($rows) {
                $perUser = $rows->whereIn('stop', $stops)->groupBy('user_id')->map->sum('points');

                return [
                    'points' => (int) $perUser->sum(),
                    'people' => $perUser->count(),
                    'regulars' => $perUser->filter(fn ($p) => $p >= self::CUP_REGULAR)->keys()->map(fn ($id) => (int) $id)->values()->all(),
                ];
            })->sortByDesc('points')->all();
        };

        // Finished months never change; this month refreshes every 5 minutes.
        return collect($monthStart->addMonthNoOverflow()->isPast() ? Cache::rememberForever($key, $compute) : Cache::remember($key, now()->addMinutes(5), $compute));
    }

    /**
     * The line that won a finished month, or null for an empty month or a tie at the top.
     */
    public static function cupWinner(CarbonImmutable $monthStart): ?string
    {
        $cup = self::lineCup($monthStart);
        [$first, $second] = [$cup->values()->get(0), $cup->values()->get(1)];

        return $first && $first['points'] > 0 && $first['points'] > ($second['points'] ?? 0) ? $cup->keys()->first() : null;
    }

    /**
     * Past winners, most recent first.
     *
     * @return Collection<int, array{month: CarbonImmutable, line: string, points: int}>
     */
    public static function cupWinners(int $months = 6): Collection
    {
        return collect(range(1, $months))->map(fn ($i) => self::monthStart()->subMonthsNoOverflow($i))
            ->map(fn ($month) => ['month' => $month, 'line' => self::cupWinner($month)])
            ->filter(fn ($row) => $row['line'])
            ->map(fn ($row) => [...$row, 'points' => self::lineCup($row['month'])[$row['line']]['points']])->values();
    }

    /**
     * Months this person was a regular on the winning line, over the last year.
     *
     * @return array<int, string>
     */
    public static function cupCrowns(User $user): array
    {
        $since = self::monthStart(($user->created_at ?? now())->toImmutable()->timezone(config('stoop.timezone')));
        $crowns = [];
        for ($month = self::monthStart()->subMonthNoOverflow(), $i = 0; $month->gte($since) && $i < 12; $month = $month->subMonthNoOverflow(), $i++) {
            $line = self::cupWinner($month);
            if ($line && in_array($user->id, self::lineCup($month)[$line]['regulars'], true)) {
                $crowns[] = $month->format('Y-m');
            }
        }

        return $crowns;
    }
}
