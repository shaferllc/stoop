<?php

namespace App\Support;

use App\Models\Dispatch;
use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * DC teams' schedules, live scores and standings from ESPN's public JSON, plus the fan
 * side: favorite teams, the pick'em leaderboard and watch parties.
 */
class Sports
{
    protected const ESPN = 'https://site.api.espn.com/apis';

    /** @return array<string, array<string, mixed>> */
    public static function teams(): array
    {
        return config('sports.teams');
    }

    /** The team someone named ("Caps", "nats", "terps football"), longest name first so "terps football" beats "terps". */
    public static function findTeam(string $text): ?string
    {
        $text = ' '.Str::lower(preg_replace('/[^\pL\pN.\s]+/u', ' ', $text)).' ';

        return collect(self::teams())->flatMap(fn ($team, $key) => collect([$key, ...$team['names']])->mapWithKeys(fn ($name) => [Str::lower($name) => $key]))
            ->sortByDesc(fn ($key, $name) => strlen($name))
            ->first(fn ($key, $name) => str_contains($text, ' '.$name.' '));
    }

    /**
     * Games that are on now, or should have started: those are the ones to refresh every five minutes.
     */
    public static function liveTeamKeys(): Collection
    {
        return Game::whereNotNull('external_id')
            ->where(fn ($q) => $q->where('status', 'live')
                ->orWhere(fn ($q) => $q->where('status', 'pre')->whereBetween('starts_at', [now()->subHours(5), now()->addMinutes(10)])))
            ->distinct()->pluck('team_key');
    }

    /**
     * Imports schedules and scores: every team once an hour, and only the teams playing
     * when a game is live. Returns how many games were stored, or null when it skipped.
     */
    public static function sync(bool $force = false): ?int
    {
        $live = self::liveTeamKeys();
        $full = $force || ! Cache::has('sports:synced');

        if (! $full && $live->isEmpty()) {
            return null;
        }

        $stored = 0;
        foreach ($full ? array_keys(self::teams()) : $live->all() as $key) {
            $stored += self::importTeam($key);
        }

        if ($full) {
            foreach (array_keys(self::teams()) as $key) {
                self::refreshStandings($key);
            }
            Cache::put('sports:synced', now()->toIso8601String(), now()->addMinutes(55));
        }

        return $stored;
    }

    /**
     * One team's schedule. ESPN's default schedule is the current season type, which is empty
     * in a preseason or a postseason without us, so the regular season is fetched too.
     */
    public static function importTeam(string $key): int
    {
        $team = self::teams()[$key];
        $url = self::ESPN.'/site/v2/sports/'.$team['espn'].'/schedule';
        $events = collect([self::fetch($url.'?seasontype=2'), self::fetch($url)])
            ->flatMap(fn ($json) => $json['events'] ?? [])->keyBy('id');

        return $events->map(fn ($event) => self::upsert($key, $event))->filter()->count();
    }

    /**
     * Stores one ESPN event for a DC team. Safe to run again: the row is keyed by team and event.
     *
     * @param  array<string, mixed>  $event
     */
    public static function upsert(string $key, array $event): ?Game
    {
        $team = self::teams()[$key];
        $comp = $event['competitions'][0] ?? null;
        $competitors = collect($comp['competitors'] ?? []);
        $us = $competitors->first(fn ($c) => (string) ($c['team']['id'] ?? $c['id'] ?? '') === $team['espn_id']);
        $them = $competitors->first(fn ($c) => $c !== $us);

        if (! isset($event['id'], $event['date']) || ! $us || ! $them) {
            return null;
        }

        $type = $comp['status']['type'] ?? $event['status']['type'] ?? [];
        $status = match (true) {
            (bool) ($type['completed'] ?? false) => 'final',
            ($type['state'] ?? 'pre') === 'in' => 'live',
            ($type['state'] ?? 'pre') === 'post' => 'off', // postponed or canceled
            default => 'pre',
        };
        // The schedule feed has {value, displayValue}; the scoreboard feed has a plain string.
        $score = fn ($c) => isset($c['score']) ? (int) (is_array($c['score']) ? ($c['score']['value'] ?? $c['score']['displayValue'] ?? 0) : $c['score']) : null;
        $home = ($us['homeAway'] ?? '') === 'home';

        $game = Game::firstOrNew(['team_key' => $key, 'external_id' => (string) $event['id']]);
        $game->fill([
            'team' => $team['name'],
            'opponent' => Str::limit((string) ($them['team']['displayName'] ?? $them['team']['name'] ?? 'TBD'), 47),
            'home' => $home,
            'starts_at' => Carbon::parse($event['date']),
            'league' => $team['league'],
            'season' => $event['season']['year'] ?? null,
            'status' => $status,
            'status_detail' => ($event['timeValid'] ?? true) === false && $status === 'pre' ? 'TBA' : (Str::limit((string) ($type['shortDetail'] ?? ''), 57) ?: null),
            'team_score' => in_array($status, ['live', 'final'], true) ? $score($us) : null,
            'opponent_score' => in_array($status, ['live', 'final'], true) ? $score($them) : null,
            'venue' => Str::limit((string) ($comp['venue']['fullName'] ?? ($home ? $team['venue'] : '')), 117) ?: null,
            'stop' => $home && ! ($comp['neutralSite'] ?? false) ? $team['stop'] : null,
        ]);

        // A rescheduled game moves its pick deadline with it.
        if ($game->dispatch_id && $status === 'pre' && $game->isDirty('starts_at')) {
            Dispatch::whereKey($game->dispatch_id)->update(['closes_at' => $game->starts_at]);
        }

        // Settle the picks once: the existing game-day scoring reads `winner`. Draws score nobody.
        if ($status === 'final' && ! $game->winner) {
            $game->winner = match (true) {
                (bool) ($us['winner'] ?? false) => 'team',
                (bool) ($them['winner'] ?? false) => 'opponent',
                $game->team_score > $game->opponent_score => 'team',
                $game->team_score < $game->opponent_score => 'opponent',
                default => 'draw',
            };
            if ($game->dispatch_id) {
                Dispatch::whereKey($game->dispatch_id)->where('closes_at', '>', now())->update(['closes_at' => now()]);
            }
        }

        $game->save();

        return $game;
    }

    /** @return array<string, mixed>|null */
    protected static function fetch(string $url): ?array
    {
        // Short cache, so the five-minute live refresh still sees new scores.
        return Cache::remember('espn:'.md5($url), now()->addMinutes(4), function () use ($url) {
            try {
                return Http::timeout(8)->acceptJson()->get($url)->throw()->json();
            } catch (Throwable $e) {
                Log::info('ESPN fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

                return null;
            }
        });
    }

    /**
     * A team's conference or league standing, refreshed by the hourly sync. Pages only read the cache.
     *
     * @return array{group: string, record: ?string, seed: ?string}|null
     */
    public static function standings(string $key): ?array
    {
        return Cache::get('sports:standings:'.$key);
    }

    protected static function refreshStandings(string $key): void
    {
        $team = self::teams()[$key];
        $league = Str::before($team['espn'], '/teams/');
        $json = self::fetch(self::ESPN.'/v2/sports/'.$league.'/standings');

        // Conferences, and in college football divisions inside them.
        $find = function (array $node) use (&$find, $team): ?array {
            foreach ($node['children'] ?? [] as $group) {
                foreach ($group['standings']['entries'] ?? [] as $entry) {
                    if ((string) ($entry['team']['id'] ?? '') === $team['espn_id']) {
                        $stat = fn (array $names) => collect($entry['stats'] ?? [])->first(fn ($s) => in_array($s['name'] ?? $s['type'] ?? '', $names, true))['displayValue'] ?? null;

                        return ['group' => (string) $group['name'], 'record' => $stat(['overall', 'total']), 'seed' => $stat(['playoffSeed', 'rank'])];
                    }
                }
                if ($found = $find($group)) {
                    return $found;
                }
            }

            return null;
        };

        if ($json && $row = $find($json)) {
            Cache::put('sports:standings:'.$key, $row, now()->addDay());
        }
    }

    /** @return list<string> */
    public static function favorites(User $user): array
    {
        return DB::table('team_fans')->where('user_id', $user->id)->pluck('team_key')
            ->filter(fn ($key) => isset(self::teams()[$key]))->values()->all();
    }

    /**
     * @param  array<int, string>  $keys
     * @return list<string>
     */
    public static function setFavorites(User $user, array $keys): array
    {
        $keys = array_values(array_intersect(array_keys(self::teams()), $keys));

        DB::transaction(function () use ($user, $keys) {
            DB::table('team_fans')->where('user_id', $user->id)->whereNotIn('team_key', $keys)->delete();
            DB::table('team_fans')->insertOrIgnore(array_map(fn ($key) => ['user_id' => $user->id, 'team_key' => $key, 'created_at' => now(), 'updated_at' => now()], $keys));
        });

        return $keys;
    }

    /** @return array<string, int> team key => fans */
    public static function fanCounts(): array
    {
        return DB::table('team_fans')->join('users', 'users.id', '=', 'team_fans.user_id')->where('users.is_bot', false)
            ->groupBy('team_key')->pluck(DB::raw('count(*)'), 'team_key')->map(fn ($n) => (int) $n)->all();
    }

    /**
     * Season-long pick'em: right picks per person on the league's game threads this season.
     *
     * @return Collection<int, object{username: string, name: string, right: int}>
     */
    public static function leaderboard(string $league, int $limit = 10): Collection
    {
        $season = Game::where('league', $league)->max('season');

        if (! $season) {
            return collect();
        }

        return DB::table('poll_votes')
            ->join('games', 'games.dispatch_id', '=', 'poll_votes.dispatch_id')
            ->join('users', 'users.id', '=', 'poll_votes.user_id')
            ->where('games.league', $league)->where('games.season', $season)->where('users.is_bot', false)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('games.winner', 'team')->whereColumn('poll_votes.poll_option_id', 'games.team_option_id'))
                ->orWhere(fn ($q) => $q->where('games.winner', 'opponent')->whereColumn('poll_votes.poll_option_id', 'games.opponent_option_id')))
            ->groupBy('users.id', 'users.username', 'users.name')
            ->select('users.username', 'users.name', DB::raw('count(*) as `right`'))
            ->orderByDesc('right')->orderBy('users.username')->take($limit)->get();
    }

    /**
     * Links the author's Happening to an upcoming game as a watch party. False if it doesn't qualify.
     */
    public static function linkWatchParty(Dispatch $happening, int $gameId): bool
    {
        $game = Game::whereKey($gameId)->whereIn('status', ['pre', 'live'])->where('starts_at', '>', now()->subHours(4))->first();

        if (! $game || $happening->type !== 'happening' || $happening->parent_id) {
            return false;
        }

        DB::table('watch_parties')->updateOrInsert(['dispatch_id' => $happening->id], ['game_id' => $game->id, 'created_at' => now(), 'updated_at' => now()]);

        return true;
    }

    /**
     * Games people can host a watch party for: the next two weeks.
     *
     * @return Collection<int, Game>
     */
    public static function upcoming(int $days = 14): Collection
    {
        return Game::whereNotNull('team_key')->whereIn('status', ['pre', 'live'])
            ->whereBetween('starts_at', [now()->subHours(3), now()->addDays($days)])->orderBy('starts_at')->get();
    }

    /**
     * Today's DC games, in DC time.
     *
     * @return Collection<int, Game>
     */
    public static function today(): Collection
    {
        $day = now(config('stoop.timezone'));

        return Game::whereNotNull('team_key')->whereBetween('starts_at', [$day->copy()->startOfDay()->utc(), $day->copy()->endOfDay()->utc()])
            ->orderBy('starts_at')->get();
    }

    /**
     * A game for the app API.
     *
     * @return array<string, mixed>
     */
    public static function present(Game $game): array
    {
        return [
            'id' => $game->id,
            'team_key' => $game->team_key,
            'team' => $game->team,
            'opponent' => $game->opponent,
            'home' => $game->home,
            'league' => $game->league,
            'starts_at' => $game->starts_at->toIso8601String(),
            'status' => $game->status,
            'status_detail' => $game->status_detail,
            'team_score' => $game->team_score,
            'opponent_score' => $game->opponent_score,
            'winner' => $game->winner,
            'venue' => $game->venue,
            'stop' => $game->stop,
            'thread_id' => $game->dispatch_id,
        ];
    }
}
