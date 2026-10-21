<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The Metro collecting game: which stations someone has posted from, and who's the
 * mayor of each stop.
 */
class Stations
{
    public const MAYOR_DAYS = 30;

    public const MAYOR_MINIMUM = 3;

    public static function name(string $code): ?string
    {
        return config("metro.stations.$code.0");
    }

    public static function jurisdiction(string $code): ?string
    {
        return config("metro.stations.$code.1");
    }

    /**
     * The station some text names, by code ("A03", "C01") or name ("dupont", "Columbia Heights",
     * "Gallery Pl-Chinatown"). Picks the first name mentioned, then the longest. Null if none.
     */
    public static function find(string $text): ?string
    {
        $code = strtoupper(trim($text));
        if (config("metro.stations.$code")) {
            return $code;
        }
        if ($alias = config("metro.aliases.$code")) {
            return $alias;
        }

        $plain = fn (string $s) => ' '.trim(preg_replace(
            ['/[’\']/u', '/[^a-z0-9]+/', '/\bsquare\b/', '/\bheights\b/', '/\bavenue\b/', '/\bstreet\b/', '/\broad\b/', '/\bmount\b/', '/\bplace\b/'],
            ['', ' ', 'sq', 'hts', 'ave', 'st', 'rd', 'mt', 'pl'],
            mb_strtolower($s),
        )).' ';
        $haystack = $plain($text);
        $names = collect(config('metro.stations'))->map(fn ($station) => $plain($station[0]));

        $match = $names->map(fn ($name) => strpos($haystack, $name))->filter(fn ($at) => $at !== false)
            ->sortBy(fn ($at, $c) => [$at, -strlen($names[$c])])->keys()->first();

        return $match ?? (strlen(trim($haystack)) >= 3 ? $names->search(fn ($name) => str_starts_with($name, $haystack)) ?: null : null);
    }

    public static function eastOfTheRiver(string $code): bool
    {
        return config("metro.stations.$code.2") === 'east';
    }

    /**
     * @return array<int, string>
     */
    public static function linesAt(string $code): array
    {
        return array_keys(array_filter(config('metro.lines'), fn ($stops) => in_array($code, $stops, true)));
    }

    /**
     * Stations someone has posted from.
     *
     * @return array<int, string>
     */
    public static function visited(User $user): array
    {
        return $user->dispatches()->where('status', 'approved')->whereNotNull('stop')->distinct()->pluck('stop')->all();
    }

    /**
     * Helpfulness points per person per stop over a window: 1 per post at the stop, 1 per
     * reply to someone else's post there, 5 per Best Answer there.
     *
     * @param  array<int, string>|null  $stops
     * @return Collection<int, object{stop: string, user_id: int, points: int}>
     */
    public static function points(\DateTimeInterface $since, ?\DateTimeInterface $until = null, ?array $stops = null): Collection
    {
        $until ??= now();
        $within = fn ($q, $column) => $q->whereBetween($column, [$since, $until]);

        // @magazine's event listings carry a stop, but the magazine never plays for mayor.
        $posts = DB::table('dispatches')->whereNull('deleted_at')->where('status', 'approved')->whereNull('parent_id')->whereNotNull('stop')
            ->whereNotIn('user_id', DB::table('users')->where('username', config('stoop.magazine_account'))->select('id'))
            ->tap(fn ($q) => $within($q, 'created_at'))->when($stops, fn ($q) => $q->whereIn('stop', $stops))
            ->groupBy('stop', 'user_id')->selectRaw('stop, user_id, count(*) as points')->get();

        $replies = DB::table('dispatches as r')->join('dispatches as p', 'p.id', '=', 'r.parent_id')
            ->whereNull('r.deleted_at')->where('r.status', 'approved')->whereNotNull('p.stop')->whereColumn('r.user_id', '!=', 'p.user_id')
            ->tap(fn ($q) => $within($q, 'r.created_at'))->when($stops, fn ($q) => $q->whereIn('p.stop', $stops))
            ->groupBy('p.stop', 'r.user_id')->selectRaw('p.stop as stop, r.user_id as user_id, count(*) as points')->get();

        $best = DB::table('dispatches as q')->join('dispatches as a', 'a.id', '=', 'q.best_answer_id')
            ->whereNotNull('q.stop')->whereNull('a.deleted_at')
            ->tap(fn ($q) => $within($q, 'a.created_at'))->when($stops, fn ($q) => $q->whereIn('q.stop', $stops))
            ->groupBy('q.stop', 'a.user_id')->selectRaw('q.stop as stop, a.user_id as user_id, count(*) * 5 as points')->get();

        return $posts->concat($replies)->concat($best)
            ->filter(fn ($row) => $row->user_id)
            ->groupBy(fn ($row) => $row->stop.':'.$row->user_id)
            ->map(fn ($rows) => (object) ['stop' => $rows->first()->stop, 'user_id' => (int) $rows->first()->user_id, 'points' => (int) $rows->sum('points')])
            ->values();
    }

    /**
     * The current mayor of every stop: the most helpful person there over the last 30 days,
     * with at least a few points. Ties go to whoever got there first (lower id).
     *
     * @return array<string, array{user_id: int, points: int}>
     */
    public static function mayors(): array
    {
        return Cache::remember('stoop:mayors', now()->addMinutes(10), fn () => self::points(now()->subDays(self::MAYOR_DAYS))
            ->where('points', '>=', self::MAYOR_MINIMUM)
            ->sortBy([['points', 'desc'], ['user_id', 'asc']])
            ->groupBy('stop')
            ->map(fn ($rows) => ['user_id' => $rows->first()->user_id, 'points' => $rows->first()->points])
            ->all());
    }

    public static function mayorOf(string $stop): ?User
    {
        $mayor = self::mayors()[$stop] ?? null;

        return $mayor ? User::find($mayor['user_id']) : null;
    }

    /**
     * @return array<int, string>
     */
    public static function mayorships(User $user): array
    {
        return array_keys(array_filter(self::mayors(), fn ($m) => $m['user_id'] === $user->id));
    }
}
