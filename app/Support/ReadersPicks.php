<?php

namespace App\Support;

use App\Models\Dispatch;
use App\Models\Place;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Readers' picks for the magazine's lists: per place, how many neighbors checked in with
 * Been here, their recent posts, and a plain word-count sentiment (no AI).
 */
class ReadersPicks
{
    // ponytail: a tiny word list, so sarcasm and "not bad" fool it; good enough to rank, not to quote
    protected const POSITIVE = ['love', 'loved', 'great', 'amazing', 'best', 'delicious', 'perfect', 'excellent', 'fantastic', 'favorite', 'friendly', 'fresh', 'worth', 'awesome', 'incredible', 'solid', 'cozy', 'tasty', 'recommend', 'gem', 'good', 'wonderful', 'fun'];

    protected const NEGATIVE = ['bad', 'worst', 'terrible', 'awful', 'rude', 'slow', 'overpriced', 'cold', 'bland', 'dirty', 'disappointing', 'disappointed', 'mediocre', 'meh', 'stale', 'avoid', 'gross', 'pricey', 'noisy', 'skip', 'soggy', 'burnt'];

    /**
     * -1 (all negative words) to 1 (all positive), 0 when there are none.
     */
    public static function sentiment(string $text): float
    {
        $words = array_count_values(preg_split('/[^a-z]+/', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY));
        $pos = array_sum(array_intersect_key($words, array_flip(self::POSITIVE)));
        $neg = array_sum(array_intersect_key($words, array_flip(self::NEGATIVE)));

        return $pos + $neg ? ($pos - $neg) / ($pos + $neg) : 0.0;
    }

    /**
     * @return Collection<int, string>
     */
    public static function lists(): Collection
    {
        return DB::table('places')->whereNotNull('list_name')->distinct()->orderBy('list_name')->pluck('list_name');
    }

    /**
     * Every place on a list with its numbers over the last N days, most check-ins first.
     *
     * @return Collection<int, array{place: Place, checkins: int, posts: int, sentiment: ?float, recent: Collection<int, Dispatch>}>
     */
    public static function forList(string $list, int $days = 90): Collection
    {
        $since = now()->subDays($days);
        $places = Place::where('list_name', $list)->orderByRaw('list_rank is null, list_rank')->orderBy('name')->get();

        $checkins = DB::table('marks')->join('dispatches', 'dispatches.id', '=', 'marks.dispatch_id')
            ->whereIn('dispatches.place_id', $places->pluck('id'))->where('dispatches.status', 'approved')->whereNull('dispatches.deleted_at')
            ->where('marks.kind', 'been')->where('marks.created_at', '>=', $since)
            ->groupBy('dispatches.place_id')->selectRaw('dispatches.place_id, count(distinct marks.user_id) as c')->pluck('c', 'place_id');

        $posts = Dispatch::with('user:id,username')->whereIn('place_id', $places->pluck('id'))->where('type', 'been')->whereNull('parent_id')
            ->where('status', 'approved')->where('created_at', '>=', $since)->latest()->get()->groupBy('place_id');

        return $places->map(function (Place $place) use ($checkins, $posts) {
            $mine = $posts[$place->id] ?? collect();

            return [
                'place' => $place,
                'checkins' => (int) ($checkins[$place->id] ?? 0),
                'posts' => $mine->count(),
                'sentiment' => $mine->isEmpty() ? null : round($mine->avg(fn ($d) => self::sentiment($d->body)), 2),
                'recent' => $mine->take(3),
            ];
        })->sortByDesc('checkins')->values();
    }

    /**
     * The report as CSV rows. Cells that a spreadsheet would read as a formula get a quote.
     *
     * @return array<int, array<int, string|int|float|null>>
     */
    public static function csv(string $list, int $days = 90): array
    {
        $safe = fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;

        return [
            ['rank', 'place', 'neighborhood', 'stop', 'checkins', 'been_here_posts', 'avg_sentiment', 'recent_posts'],
            ...self::forList($list, $days)->map(fn ($row) => array_map($safe, [
                $row['place']->list_rank,
                $row['place']->name,
                $row['place']->neighborhood,
                $row['place']->stop,
                $row['checkins'],
                $row['posts'],
                $row['sentiment'],
                $row['recent']->map(fn ($d) => '@'.$d->user?->username.': '.str_replace("\n", ' ', $d->body))->join(' | '),
            ]))->all(),
        ];
    }
}
