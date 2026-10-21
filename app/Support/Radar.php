<?php

namespace App\Support;

use Anthropic\Client;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Story radar for editors: what's growing on Stoop this week. Compares the last 7 days with
 * the weekly average of the 4 weeks before, per line (topic), per station and per word, and
 * finds Ask DC questions piling up around the same place or name. Bots and @magazine don't count.
 */
class Radar
{
    // This week needs at least this many posts and replies before a trend shows.
    public const MIN_VOLUME = 5;

    // Below this weekly average, a trend is "new" instead of a huge percentage.
    public const MIN_BASELINE = 2;

    // Only show trends up at least this much.
    public const MIN_CHANGE = 25;

    // Ask DC posts about the same name that make a cluster.
    public const CLUSTER_MIN = 5;

    protected const STOPWORDS = ['the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'any', 'can', 'had', 'her', 'was', 'one', 'our', 'out', 'has', 'him', 'his', 'how', 'its', 'may', 'new', 'now', 'old', 'see', 'two', 'way', 'who', 'did', 'get', 'got', 'let', 'say', 'she', 'too', 'use', 'yes', 'yet', 'just', 'like', 'this', 'that', 'with', 'have', 'from', 'they', 'will', 'what', 'when', 'where', 'which', 'there', 'their', 'them', 'then', 'than', 'been', 'were', 'would', 'could', 'should', 'about', 'into', 'over', 'also', 'some', 'more', 'most', 'other', 'only', 'very', 'much', 'many', 'such', 'here', 'your', 'yours', 'mine', 'does', 'doing', 'done', 'know', 'think', 'really', 'good', 'great', 'anyone', 'someone', 'something', 'anything', 'thing', 'things', 'people', 'still', 'even', 'well', 'back', 'after', 'before', 'because', 'going', 'today', 'tonight', 'week', 'year', 'time', 'make', 'made', 'want', 'need', 'look', 'looking', 'right', 'these', 'those', 'every', 'being', 'while', 'again', 'off', 'why', 'yeah', 'lol', 'thanks', 'thank', 'please', 'any', 'each', 'same', 'own', 'last', 'next', 'first', 'around', 'near', 'place', 'places', 'best', 'love', "i'm", "it's", "don't", "can't", "i've", "that's", 'dont', 'cant', 'im'];

    protected static ?Closure $fake = null;

    /**
     * Percent change of this week against the weekly average, or null when the average is too
     * small for a percentage to mean anything.
     */
    public static function change(int $current, float $weeklyAverage): ?int
    {
        return $weeklyAverage >= self::MIN_BASELINE ? (int) round(($current - $weeklyAverage) / $weeklyAverage * 100) : null;
    }

    /**
     * A trend if it's big enough and growing, otherwise null.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>|null
     */
    public static function trend(string $label, int $current, int $prior4Weeks, array $extra = []): ?array
    {
        $average = $prior4Weeks / 4;
        $change = self::change($current, $average);

        if ($current < self::MIN_VOLUME || ($change !== null && $change < self::MIN_CHANGE)) {
            return null;
        }

        return ['label' => $label, 'current' => $current, 'average' => round($average, 1), 'change' => $change] + $extra;
    }

    /**
     * The radar, worked out at most every 10 minutes.
     *
     * @return array{lines: list<array<string, mixed>>, stations: list<array<string, mixed>>, keywords: list<array<string, mixed>>, clusters: list<array<string, mixed>>, generated_at: string}
     */
    public static function get(): array
    {
        return Cache::remember('radar', now()->addMinutes(10), fn () => self::build());
    }

    public static function build(): array
    {
        $now = now();
        $weekAgo = $now->copy()->subDays(7);
        $start = $now->copy()->subDays(35);

        // Replies have no stop of their own; they count toward their thread's station.
        $rows = self::activity($start, $now)
            ->leftJoin('dispatches as p', 'p.id', '=', 'd.parent_id')
            ->selectRaw('d.line as line, coalesce(d.stop, p.stop) as stop, d.parent_id is not null as reply, d.created_at >= ? as recent, count(*) as n', [$weekAgo])
            ->groupByRaw('d.line, coalesce(d.stop, p.stop), d.parent_id is not null, d.created_at >= ?', [$weekAgo])
            ->get();

        $sum = function (string $key) use ($rows) {
            $out = [];
            foreach ($rows as $r) {
                if ($r->{$key} === null) {
                    continue;
                }
                $bucket = &$out[$r->{$key}];
                $bucket ??= ['posts' => 0, 'replies' => 0, 'prior' => 0];
                $r->recent ? $bucket[$r->reply ? 'replies' : 'posts'] += $r->n : $bucket['prior'] += $r->n;
            }

            return $out;
        };

        $lines = [];
        foreach ($sum('line') as $line => $c) {
            $lines[] = self::trend(config("stoop.lines.$line.name", $line).' line', $c['posts'] + $c['replies'], $c['prior'],
                ['key' => "line:$line", 'posts' => $c['posts'], 'replies' => $c['replies'], 'url' => route('home', ['line' => $line, 'tab' => 'latest'])]);
        }

        $stations = [];
        foreach ($sum('stop') as $stop => $c) {
            $stations[] = self::trend(config("stoop.stations.$stop", $stop), $c['posts'] + $c['replies'], $c['prior'],
                ['key' => "stop:$stop", 'posts' => $c['posts'], 'replies' => $c['replies'], 'url' => route('home', ['stop' => $stop, 'tab' => 'latest'])]);
        }

        return [
            'lines' => self::ranked($lines),
            'stations' => self::ranked($stations),
            'keywords' => self::keywords($start, $weekAgo, $now),
            'clusters' => self::clusters($weekAgo, $now),
            'generated_at' => $now->toIso8601String(),
        ];
    }

    /**
     * Words used in more posts this week than usual. Counts each post once per word.
     *
     * @return list<array<string, mixed>>
     */
    protected static function keywords(CarbonInterface $start, CarbonInterface $weekAgo, CarbonInterface $now): array
    {
        $recent = $prior = [];
        // ponytail: reads up to 5,000 recent bodies; move to the FTS vocabulary table if volume grows
        foreach (self::activity($start, $now)->latest('d.created_at')->take(5000)->get(['d.body', 'd.created_at']) as $d) {
            foreach (self::terms($d->body) as $term) {
                $d->created_at >= $weekAgo->toDateTimeString() ? $recent[$term] = ($recent[$term] ?? 0) + 1 : $prior[$term] = ($prior[$term] ?? 0) + 1;
            }
        }

        $trends = [];
        foreach ($recent as $term => $n) {
            $trends[] = self::trend($term, $n, $prior[$term] ?? 0, ['key' => "word:$term", 'url' => route('search', ['q' => $term])]);
        }

        return array_slice(self::ranked($trends), 0, 15);
    }

    /**
     * The distinct words in a post worth counting: no links, @mentions, numbers or stopwords.
     *
     * @return list<string>
     */
    public static function terms(string $body): array
    {
        $body = preg_replace(['#https?://\S+#i', '/(?<![\w.])@\w+/', '/[’‘]/u'], [' ', ' ', "'"], mb_strtolower($body));
        preg_match_all("/\p{L}[\p{L}'-]{2,}/u", $body, $m);

        return array_values(array_unique(array_filter(array_map(fn ($w) => trim($w, "'-"), $m[0]), fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, self::STOPWORDS, true))));
    }

    /**
     * Ask DC questions this week mentioning the same place, station, neighborhood or name.
     *
     * @return list<array<string, mixed>>
     */
    protected static function clusters(CarbonInterface $weekAgo, CarbonInterface $now): array
    {
        $names = Archive::names();
        $groups = [];

        $asks = self::activity($weekAgo, $now)->whereNull('d.parent_id')->where('d.type', 'ask')
            ->leftJoin('places as pl', 'pl.id', '=', 'd.place_id')->get(['d.id', 'd.body', 'pl.name as place']);

        foreach ($asks as $d) {
            $tags = Archive::tag($d->body, $names);
            $found = [
                ...($d->place ? [$d->place] : []),
                ...array_map(fn ($code) => config("stoop.stations.$code"), $tags['stations']),
                ...$tags['neighborhoods'],
                ...self::properNames($d->body),
            ];
            foreach (array_unique($found) as $name) {
                $groups[$name][] = $d->id;
            }
        }

        return collect($groups)->filter(fn ($ids) => count($ids) >= self::CLUSTER_MIN)
            ->sortByDesc(fn ($ids) => count($ids))->take(10)
            ->map(fn ($ids, $name) => [
                'key' => 'cluster:'.$name,
                'label' => $name,
                'current' => count($ids),
                'post_ids' => array_slice($ids, 0, 10),
                'url' => route('search', ['q' => $name]),
            ])->values()->all();
    }

    /**
     * Capitalized names in the middle of a sentence ("the line at Call Your Mother"),
     * up to three words long.
     *
     * @return list<string>
     */
    public static function properNames(string $text): array
    {
        preg_match_all("/(?<=[\p{Ll}\p{N},;:'\"()]\s)\p{Lu}[\p{L}'’]+(?:\s\p{Lu}[\p{L}'’]+){0,2}/u", $text, $m);

        return array_values(array_unique(array_filter($m[0], fn ($n) => mb_strlen($n) >= 3 && ! in_array(mb_strtolower($n), self::STOPWORDS, true))));
    }

    /**
     * @param  list<array<string, mixed>|null>  $trends
     * @return list<array<string, mixed>>
     */
    protected static function ranked(array $trends): array
    {
        // New trends (no baseline) first, then by percent change, then by volume.
        return collect($trends)->filter()->sortBy([fn ($a, $b) => ($b['change'] ?? PHP_INT_MAX) <=> ($a['change'] ?? PHP_INT_MAX), fn ($a, $b) => $b['current'] <=> $a['current']])
            ->values()->all();
    }

    /**
     * Public posts and replies in a window, from people (not bots or the magazine).
     */
    protected static function activity(CarbonInterface $from, CarbonInterface $to): Builder
    {
        return DB::table('dispatches as d')->join('users as u', 'u.id', '=', 'd.user_id')
            ->where('d.status', 'approved')->whereNull('d.deleted_at')
            ->where('d.created_at', '>=', $from)->where('d.created_at', '<', $to)
            ->where('u.is_bot', false)->where('u.username', '!=', config('stoop.magazine_account'));
    }

    // Story ideas from Claude...

    public static function ideasEnabled(): bool
    {
        return self::$fake !== null || (bool) Ai::key('content');
    }

    /**
     * @param  Closure(string): string  $fake
     */
    public static function fake(?Closure $fake): void
    {
        self::$fake = $fake;
    }

    /**
     * A one-paragraph story idea for a trend, from Claude, cached for the day. Null without
     * an API key or when Claude can't help. It only suggests; editors decide.
     *
     * @param  array<string, mixed>  $trend
     */
    public static function idea(array $trend): ?string
    {
        if (! self::ideasEnabled()) {
            return null;
        }

        return Cache::remember('radar:idea:'.md5($trend['key']).':'.now()->toDateString(), now()->addDay(), function () use ($trend) {
            $samples = DB::table('dispatches')->where('status', 'approved')->whereIn('id', self::sampleIds($trend))->pluck('body')
                ->map(fn ($b) => '- '.Str::limit($b, 300))->join("\n");
            $prompt = "Trend: {$trend['label']} ({$trend['current']} posts and replies this week"
                .(isset($trend['change']) ? ", {$trend['change']}% above the usual week" : '').")\n\nRecent posts:\n".$samples;

            if (self::$fake) {
                return (self::$fake)($prompt);
            }

            try {
                $message = (new Client(apiKey: Ai::key('content')))->messages->create(
                    model: config('services.anthropic.model'),
                    maxTokens: 1024,
                    outputConfig: ['effort' => 'low'],
                    system: 'You help the editors of a Washington, DC city magazine find stories. Given a trend on Stoop, the magazine’s neighborhood social network, and a few recent posts, suggest one story idea in a single plain-text paragraph of at most 90 words: the angle, who to call, and what to check. Never name or quote the people who posted.',
                    messages: [['role' => 'user', 'content' => $prompt]],
                );

                if ($message->stopReason === 'refusal') {
                    return null;
                }

                return trim((string) collect($message->content)->firstWhere('type', 'text')?->text) ?: null;
            } catch (Throwable $e) {
                Log::warning('Radar story idea failed', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }

    /**
     * A few recent posts behind a trend, for the idea prompt and for editors to read.
     *
     * @param  array<string, mixed>  $trend
     * @return list<int>
     */
    public static function sampleIds(array $trend): array
    {
        if (isset($trend['post_ids'])) {
            return $trend['post_ids'];
        }

        [$kind, $value] = explode(':', $trend['key'], 2);
        $query = self::activity(now()->subDays(7), now())->leftJoin('dispatches as p', 'p.id', '=', 'd.parent_id');
        match ($kind) {
            'line' => $query->where('d.line', $value),
            'stop' => $query->whereRaw('coalesce(d.stop, p.stop) = ?', [$value]),
            default => $query->where('d.body', 'like', '%'.$value.'%'),
        };

        return $query->latest('d.created_at')->take(8)->pluck('d.id')->all();
    }
}
