<?php

namespace App\Support;

use Anthropic\Client;
use App\Models\User;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Aggregate, anonymized reader numbers for advertisers: per station and per line (topic),
 * Been here check-ins, rising keywords, games and a simple word-list sentiment.
 *
 * k-anonymity: any number drawn from fewer than K distinct people shows as "<10". There are
 * no usernames, posts or emails anywhere in the report, and bots, staff and the magazine's
 * account are left out. Stations stand in for neighborhoods until stations get neighborhood data.
 */
class ReaderInsights
{
    public const K = 10;

    public const SUPPRESSED = '<10';

    // ponytail: tiny word lists; good enough to show a lean, swap for a real lexicon if sales leans on it.
    public const POSITIVE = ['love', 'loved', 'great', 'best', 'amazing', 'awesome', 'good', 'nice', 'beautiful', 'delicious', 'favorite', 'fun', 'happy', 'friendly', 'excellent', 'perfect', 'wonderful', 'recommend', 'thanks', 'thank', 'enjoy', 'enjoyed', 'fantastic', 'gorgeous', 'tasty', 'clean', 'safe', 'welcoming', 'glad', 'cozy'];

    public const NEGATIVE = ['bad', 'worst', 'terrible', 'awful', 'hate', 'dirty', 'broken', 'closed', 'late', 'delay', 'delayed', 'crowded', 'expensive', 'noisy', 'loud', 'rude', 'unsafe', 'dangerous', 'sad', 'angry', 'annoying', 'disappointed', 'disappointing', 'gross', 'slow', 'smell', 'trash', 'problem', 'crime', 'stolen'];

    protected const STOPWORDS = ['about', 'after', 'again', 'also', 'anyone', 'because', 'been', 'before', 'being', 'could', 'does', 'doing', 'dont', 'down', 'each', 'even', 'every', 'from', 'going', 'have', 'here', 'into', 'just', 'know', 'like', 'look', 'looking', 'made', 'make', 'many', 'more', 'most', 'much', 'need', 'only', 'other', 'over', 'people', 'really', 'right', 'said', 'same', 'should', 'some', 'still', 'such', 'than', 'that', 'thats', 'their', 'them', 'then', 'there', 'these', 'they', 'thing', 'things', 'think', 'this', 'those', 'through', 'time', 'today', 'very', 'want', 'was', 'week', 'well', 'were', 'what', 'when', 'where', 'which', 'while', 'will', 'with', 'would', 'year', 'your', 'youre', 'yours', 'cant', 'didnt', 'isnt', 'tonight', 'anybody', 'someone', 'something', 'around', 'there', 'getting', 'good', 'great', 'best'];

    protected static ?Closure $fake = null;

    /**
     * Who may see the report. There are no finer roles than staff yet; a sales role slots in here.
     */
    public static function allowed(?User $user): bool
    {
        return (bool) $user?->is_staff;
    }

    /**
     * A number, or "<10" when fewer than K distinct people are behind it.
     */
    public static function cell(int|float $value, int $people): int|float|string
    {
        return $people < self::K ? self::SUPPRESSED : $value;
    }

    /**
     * The whole report for a period, already suppressed. Every view (page, CSV, print, the
     * Claude summary) is built from this and nothing else.
     *
     * @return array<string, mixed>
     */
    public static function report(CarbonInterface $from, CarbonInterface $to): array
    {
        $posts = self::posts($from, $to);
        $lines = config('stoop.lines');

        $stations = $posts->whereNotNull('stop')->groupBy('stop')
            ->map(function (Collection $rows, string $stop) use ($lines) {
                $people = $rows->unique('user_id')->count();
                $topics = $rows->groupBy('line')->filter(fn ($r) => $r->unique('user_id')->count() >= self::K)
                    ->sortByDesc(fn ($r) => $r->count())->keys()->take(3)->map(fn ($l) => $lines[$l]['name'] ?? $l)->join(', ');

                return [
                    'station' => Stations::name($stop) ?? $stop,
                    'posts' => self::cell($rows->count(), $people),
                    'people' => self::cell($people, $people),
                    'top_topics' => $people < self::K ? self::SUPPRESSED : ($topics ?: '—'),
                    'sentiment' => self::cell(self::sentiment($rows->pluck('body')), $people),
                    '_people' => $people,
                ];
            })->sortByDesc('_people')->values()->map(fn ($row) => collect($row)->except('_people')->all())->all();

        $topics = collect($lines)->map(function ($line, $key) use ($posts) {
            $rows = $posts->where('line', $key);
            $people = $rows->unique('user_id')->count();

            return [
                'topic' => $line['name'],
                'posts' => self::cell($rows->count(), $people),
                'people' => self::cell($people, $people),
                'sentiment' => self::cell(self::sentiment($rows->pluck('body')), $people),
            ];
        })->values()->all();

        $everyone = $posts->unique('user_id')->count();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => [
                'posts' => self::cell($posts->count(), $everyone),
                'people' => self::cell($everyone, $everyone),
                'sentiment' => self::cell(self::sentiment($posts->pluck('body')), $everyone),
            ],
            'stations' => $stations,
            'topics' => $topics,
            'places' => self::places($from, $to),
            'keywords' => self::risingKeywords($posts, self::posts($from->copy()->sub($from->diff($to)), $from)),
            'participation' => self::participation($from, $to),
        ];
    }

    /**
     * Approved, public posts from real readers in the period: no bots, staff or house accounts.
     *
     * @return Collection<int, object{user_id: int, line: string, stop: ?string, body: string}>
     */
    protected static function posts(CarbonInterface $from, CarbonInterface $to): Collection
    {
        // ponytail: loads the period's posts into memory; move to SQL aggregates past ~100k posts a period.
        return DB::table('dispatches')->whereIn('user_id', self::readers())
            ->where('status', 'approved')->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to])
            ->get(['user_id', 'line', 'stop', 'body']);
    }

    /**
     * Ids of the people who count: everyone but bots, staff and the magazine's account.
     */
    protected static function readers(): Builder
    {
        return DB::table('users')->select('id')->where('is_bot', false)->where('is_staff', false)
            ->where('username', '!=', config('stoop.magazine_account'));
    }

    /**
     * Net sentiment from -100 (all negative words) to 100 (all positive); 0 with no signal.
     *
     * @param  Collection<int, string>  $bodies
     */
    public static function sentiment(Collection $bodies): int
    {
        $words = $bodies->flatMap(fn ($body) => self::words($body))->countBy();
        $positive = $words->only(self::POSITIVE)->sum();
        $negative = $words->only(self::NEGATIVE)->sum();

        return $positive + $negative ? (int) round(($positive - $negative) / ($positive + $negative) * 100) : 0;
    }

    /**
     * @return list<string>
     */
    protected static function words(string $body): array
    {
        $body = str_replace(['’', "'"], '', preg_replace(['~https?://\S+~i', '/@\w+/', '/\d+/'], ' ', mb_strtolower($body)));

        return preg_split('/[^a-z]+/', $body, -1, PREG_SPLIT_NO_EMPTY);
    }

    /**
     * Words used by at least K people this period, most new people first, against the period before.
     *
     * @return list<array{keyword: string, people: int, before: int|string}>
     */
    public static function risingKeywords(Collection $now, Collection $before, int $take = 15): array
    {
        $authors = fn (Collection $posts) => $posts
            ->flatMap(fn ($p) => collect(self::words($p->body))->unique()->reject(fn ($w) => strlen($w) < 4 || in_array($w, self::STOPWORDS, true))->map(fn ($w) => [$w, $p->user_id]))
            ->groupBy(0)->map(fn ($pairs) => $pairs->pluck(1)->unique()->count());

        $current = $authors($now)->filter(fn ($n) => $n >= self::K);
        $previous = $authors($before);

        return $current->map(fn ($n, $word) => ['keyword' => $word, 'people' => $n, 'before' => self::cell($previous[$word] ?? 0, $previous[$word] ?? 0), '_rise' => $n - ($previous[$word] ?? 0)])
            ->filter(fn ($row) => $row['_rise'] > 0)
            ->sortByDesc('_rise')->take($take)->values()
            ->map(fn ($row) => collect($row)->except('_rise')->all())->all();
    }

    /**
     * Places with the most Been here check-ins in the period, by distinct people.
     *
     * @return list<array{place: string, people: int|string}>
     */
    protected static function places(CarbonInterface $from, CarbonInterface $to): array
    {
        return DB::table('marks')->join('dispatches', 'dispatches.id', '=', 'marks.dispatch_id')
            ->join('places', 'places.id', '=', 'dispatches.place_id')
            ->where('marks.kind', 'been')->whereIn('marks.user_id', self::readers())
            ->whereBetween('marks.created_at', [$from, $to])
            ->groupBy('places.id', 'places.name')
            ->selectRaw('places.name as place, count(distinct marks.user_id) as people')
            ->orderByDesc('people')->limit(10)->get()
            ->map(fn ($row) => ['place' => $row->place, 'people' => self::cell((int) $row->people, (int) $row->people)])->all();
    }

    /**
     * Distinct people who played: The Daily quiz, game-day picks, contests and polls.
     *
     * @return list<array{activity: string, people: int|string}>
     */
    protected static function participation(CarbonInterface $from, CarbonInterface $to): array
    {
        $count = fn (string $table, ?Closure $scope = null) => (int) DB::table($table)->whereIn("$table.user_id", self::readers())
            ->whereBetween("$table.created_at", [$from, $to])->when($scope, $scope)->distinct()->count("$table.user_id");

        return collect([
            'The Daily quiz' => $count('quiz_attempts'),
            'Game-day picks' => $count('poll_votes', fn ($q) => $q->whereIn('dispatch_id', DB::table('games')->whereNotNull('dispatch_id')->select('dispatch_id'))),
            'Contests' => $count('contest_entries'),
            'Polls' => $count('poll_votes'),
        ])->map(fn ($people, $activity) => ['activity' => $activity, 'people' => self::cell($people, $people)])->values()->all();
    }

    /**
     * The report as CSV rows, one section after another.
     *
     * @return list<list<string|int|float>>
     */
    public static function csvRows(array $report): array
    {
        $rows = [['Stoop reader insights', $report['from'].' to '.$report['to']], ['Cells from fewer than '.self::K.' people show as '.self::SUPPRESSED.'.'], []];
        $rows[] = ['Totals'];
        $rows[] = array_keys($report['totals']);
        $rows[] = array_values($report['totals']);

        foreach (['stations' => 'By station', 'topics' => 'By topic (line)', 'places' => 'Top Been here places', 'keywords' => 'Rising keywords (people using the word)', 'participation' => 'Games and quizzes (people)'] as $key => $title) {
            $rows[] = [];
            $rows[] = [$title];
            if ($report[$key]) {
                $rows[] = array_keys($report[$key][0]);
                foreach ($report[$key] as $row) {
                    $rows[] = array_values($row);
                }
            }
        }

        return $rows;
    }

    public static function summaryEnabled(): bool
    {
        return self::$fake !== null || (bool) config('services.anthropic.key');
    }

    /**
     * @param  Closure(string): string  $fake
     */
    public static function fake(?Closure $fake): void
    {
        self::$fake = $fake;
    }

    /**
     * A short executive summary written by Claude from the aggregate (already suppressed)
     * numbers only. Null without ANTHROPIC_API_KEY, on a refusal, or if Claude can't be reached.
     */
    public static function summary(array $report): ?string
    {
        $numbers = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if (self::$fake) {
            return (self::$fake)($numbers);
        }

        if (! config('services.anthropic.key')) {
            return null;
        }

        try {
            $message = (new Client(apiKey: config('services.anthropic.key')))->messages->create(
                model: config('services.anthropic.model'),
                maxTokens: 1024,
                outputConfig: ['effort' => 'low'],
                system: 'You write a one-paragraph executive summary (at most 120 words) of a local social network\'s aggregate reader numbers for a magazine\'s advertising sales team. Use only the numbers given. "<10" means fewer than 10 people and must never be estimated. Sentiment runs from -100 to 100. Plain text, no lists, no headings.',
                messages: [['role' => 'user', 'content' => "<numbers>\n".$numbers."\n</numbers>"]],
            );

            if ($message->stopReason === 'refusal') {
                return null;
            }

            $text = trim((string) collect($message->content)->firstWhere('type', 'text')?->text);

            return $text !== '' ? $text : null;
        } catch (Throwable $e) {
            Log::warning('Reader insights summary failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
