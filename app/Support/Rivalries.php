<?php

namespace App\Support;

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
            });
        };

        // Finished weeks never change, so they're cached for good; this week for 5 minutes.
        return $finished ? Cache::rememberForever($key, $compute) : Cache::remember($key, now()->addMinutes(5), $compute);
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

        return Cache::remember('stoop:lines:'.$weekStart->toDateString(), now()->addMinutes(5), function () use ($weekStart) {
            $rows = Stations::points($weekStart->utc(), $weekStart->addWeek()->utc());

            return collect(config('metro.lines'))->map(function ($stops) use ($rows) {
                $mine = $rows->whereIn('stop', $stops);

                return ['points' => (int) $mine->sum('points'), 'people' => $mine->pluck('user_id')->unique()->count()];
            })->sortByDesc('points');
        });
    }
}
