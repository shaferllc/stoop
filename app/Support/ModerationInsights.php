<?php

namespace App\Support;

use App\Models\Dispatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The non-AI side of moderator help: how similar posts were decided, whether to blur a
 * post until clicked, plain facts about a person, and how well the OpenAI thresholds fit.
 */
class ModerationInsights
{
    /** Categories whose high scores are blurred in the queue until a moderator clicks. */
    public const BLUR_CATEGORIES = ['hate', 'harassment', 'sexual', 'violence'];

    protected const STOPWORDS = ['about', 'after', 'again', 'also', 'been', 'before', 'being', 'could', 'does', 'doing', 'from', 'have', 'here', 'into', 'just', 'know', 'like', 'more', 'much', 'only', 'other', 'over', 'really', 'same', 'should', 'some', 'than', 'that', 'their', 'them', 'then', 'there', 'these', 'they', 'thing', 'this', 'very', 'want', 'were', 'what', 'when', 'where', 'which', 'while', 'will', 'with', 'would', 'your'];

    /**
     * Moderated posts that share words with this one, from the search index, and how they
     * were decided: counts over the closest 25 and the 3 closest as examples.
     *
     * @return array{approved: int, removed: int, examples: list<array{id: int, body: string, status: string, reason: ?string}>}
     */
    public static function pastDecisions(Dispatch $dispatch): array
    {
        $none = ['approved' => 0, 'removed' => 0, 'examples' => []];
        if (DB::getDriverName() !== 'sqlite') {
            return $none;
        }

        // The longest distinct words carry the most meaning; any of them can match.
        $words = collect(preg_split('/\s+/', mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $dispatch->body))))
            ->filter(fn ($w) => mb_strlen($w) >= 4 && ! in_array($w, self::STOPWORDS, true))
            ->unique()->sortByDesc(fn ($w) => mb_strlen($w))->take(6);
        if ($words->isEmpty()) {
            return $none;
        }

        $ids = array_column(DB::select('SELECT rowid FROM dispatches_fts WHERE dispatches_fts MATCH ? ORDER BY rank LIMIT 300', [$words->map(fn ($w) => '"'.$w.'"')->join(' OR ')]), 'rowid');
        $order = array_flip($ids);

        // ponytail: keyword overlap, not meaning; embeddings would find paraphrases too
        $similar = Dispatch::withTrashed()->whereIn('id', $ids)->whereKeyNot($dispatch->id)
            ->whereNotNull('moderated_by')->whereIn('status', ['approved', 'removed'])
            ->get(['id', 'body', 'status', 'moderation_reason'])
            ->sortBy(fn ($d) => $order[$d->id])->take(25)->values();

        return [
            'approved' => $similar->where('status', 'approved')->count(),
            'removed' => $similar->where('status', 'removed')->count(),
            'examples' => $similar->take(3)->map(fn ($d) => [
                'id' => $d->id, 'body' => str($d->body)->limit(160)->toString(), 'status' => $d->status, 'reason' => $d->moderation_reason,
            ])->all(),
        ];
    }

    /**
     * Why a post was held beyond OpenAI's scores: watch phrases and Claude's coded-hate pass.
     *
     * @return list<string>
     */
    public static function heldBecause(Dispatch $dispatch): array
    {
        $why = array_map(fn ($p) => 'Watch phrase “'.$p['phrase'].'”: '.$p['meaning'], $dispatch->screening['watch'] ?? []);
        if ($coded = $dispatch->screening['coded'] ?? null) {
            $why[] = 'Claude’s second look for coded hate: '.$coded['reason'];
        }

        return $why;
    }

    /**
     * High hate, harassment, sexual or violence scores, or a watch phrase: blur until clicked
     * so moderators don't have to read the worst of it by default.
     */
    public static function blur(Dispatch $dispatch): bool
    {
        $s = $dispatch->screening ?? [];
        $hot = collect(array_keys($s['openai'] ?? []))->contains(fn ($c) => in_array(strtok($c, '/'), self::BLUR_CATEGORIES, true));

        return $hot || ! empty($s['watch']) || ! empty($s['coded']) || in_array(strtok((string) ($s['auto_removed'] ?? ''), '/'), self::BLUR_CATEGORIES, true);
    }

    /**
     * Plain facts about a person's record, and the same as a sentence or two.
     *
     * @return array{text: string, posts: int, removed: int, reports: int, strikes: int, penalties: array<string, int>, reasons: array<string, int>, first: ?string, last: ?string}
     */
    public static function personFacts(User $user): array
    {
        $penalties = $user->penalties()->oldest()->get(['type', 'reason', 'created_at']);
        $posts = Dispatch::withTrashed()->where('user_id', $user->id);
        $facts = [
            'posts' => (clone $posts)->count(),
            'removed' => (clone $posts)->where('status', 'removed')->count(),
            'reports' => DB::table('reports')->join('dispatches', 'dispatches.id', '=', 'reports.dispatch_id')->where('dispatches.user_id', $user->id)->count(),
            'strikes' => Strikes::recent($user),
            'penalties' => $penalties->countBy('type')->all(),
            'reasons' => $penalties->countBy('reason')->sortDesc()->all(),
            'first' => $penalties->first()?->created_at?->toFormattedDateString(),
            'last' => $penalties->last()?->created_at?->toFormattedDateString(),
        ];

        $text = $facts['posts'].' '.str('post')->plural($facts['posts']).', '.$facts['removed'].' removed, reported '.$facts['reports'].' '.str('time')->plural($facts['reports']).'. ';
        $text .= $penalties->isEmpty() ? 'No penalties.' : $penalties->count().' '.str('penalty')->plural($penalties->count())
            .' ('.collect($facts['penalties'])->map(fn ($n, $t) => $n.' '.str($t)->plural($n))->join(', ').')'
            .($facts['first'] === $facts['last'] ? ' on '.$facts['first'] : ' between '.$facts['first'].' and '.$facts['last'])
            .': '.collect($facts['reasons'])->map(fn ($n, $r) => $r.($n > 1 ? ' ×'.$n : ''))->join('; ').'. '
            .$facts['strikes'].' '.str('strike')->plural($facts['strikes']).' in the last '.Strikes::WINDOW_DAYS.' days.';

        return ['text' => $text] + $facts;
    }

    /**
     * For each OpenAI category: how many held posts moderators approved or removed in the
     * last 30 days, and a suggested threshold. Read-only; thresholds change in config/stoop.php.
     *
     * @return list<array{category: string, threshold: float, held: int, approved: int, removed: int, approval_rate: ?float, suggested: ?float, advice: string}>
     */
    public static function thresholds(int $days = 30): array
    {
        $counts = [];

        // ponytail: scans 30 days of decided posts in PHP; move to SQL JSON functions if it gets slow
        Dispatch::withTrashed()->where('created_at', '>=', now()->subDays($days))->whereNotNull('screening')
            ->whereNotNull('moderated_by')->whereIn('status', ['approved', 'removed'])
            ->get(['id', 'status', 'screening'])
            ->each(function ($d) use (&$counts) {
                // A post flagged in several categories counts in each.
                foreach (array_keys($d->screening['openai'] ?? []) as $category) {
                    $counts[$category][$d->status] = ($counts[$category][$d->status] ?? 0) + 1;
                }
            });

        return collect(config('stoop.openai_thresholds'))->map(function ($threshold, $category) use ($counts) {
            $approved = $counts[$category]['approved'] ?? 0;
            $removed = $counts[$category]['removed'] ?? 0;
            [$suggested, $advice] = self::suggest($threshold, $approved, $removed, config("stoop.openai_auto_remove.$category"));

            return [
                'category' => $category, 'threshold' => (float) $threshold, 'held' => $approved + $removed, 'approved' => $approved, 'removed' => $removed,
                'approval_rate' => $approved + $removed ? round($approved / ($approved + $removed), 2) : null,
                'suggested' => $suggested, 'advice' => $advice,
            ];
        })->values()->all();
    }

    /**
     * Simple rules: at least 5 decisions; mostly approved means the threshold catches too much,
     * so raise it by 0.05 (staying under the auto-remove score); mostly removed means posts
     * just under it are probably bad too, so lower it by 0.05.
     *
     * @return array{0: ?float, 1: string}
     */
    public static function suggest(float $threshold, int $approved, int $removed, ?float $autoRemove = null): array
    {
        $total = $approved + $removed;
        if ($total < 5) {
            return [null, 'Too few decisions to say yet.'];
        }

        $rate = $approved / $total;
        if ($rate >= 0.8) {
            $raised = round(min($threshold + 0.05, ($autoRemove ?? 1.0) - 0.05, 0.95), 2);

            return $raised > $threshold
                ? [$raised, 'Moderators approve most of these. Raise it to hold fewer harmless posts.']
                : [null, 'Moderators approve most of these, but it’s already close to the auto-remove score.'];
        }
        if ($rate <= 0.2) {
            return [round(max($threshold - 0.05, 0.01), 2), 'Moderators remove nearly all of these. Lower it to catch posts just under the line.'];
        }

        return [null, 'About right: moderators both approve and remove these.'];
    }
}
