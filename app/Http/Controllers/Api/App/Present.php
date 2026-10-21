<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Resources\DispatchResource;
use App\Models\Dispatch;
use App\Models\User;
use App\Support\Stations;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * JSON shapes for the app. Everything is built from explicit fields so private columns
 * (email, settings, subscription dates) never leak through a model's toArray().
 */
class Present
{
    /**
     * The public face of a person: what any neighbor sees next to a dispatch.
     *
     * @return array<string, mixed>|null
     */
    public static function user(?User $user): ?array
    {
        return $user ? [
            'id' => $user->id,
            'username' => $user->username,
            'name' => $user->name,
            'badge' => $user->badge(),
            'color' => $user->color(),
            'is_live' => $user->isLive(),
        ] : null;
    }

    /**
     * @return array{code: string, name: ?string, lines: array<int, string>}
     */
    public static function stop(string $code): array
    {
        return ['code' => $code, 'name' => Stations::name($code), 'lines' => Stations::linesAt($code)];
    }

    /**
     * A page of dispatch cards with the viewer's own marks and votes, like the website's cards.
     *
     * @param  Collection<int, Dispatch>  $dispatches
     * @return array<int, array<string, mixed>>
     */
    public static function dispatches(Collection $dispatches, ?User $viewer): array
    {
        $ids = $dispatches->pluck('id')->all();
        $marks = $votes = [];

        if ($viewer && $ids) {
            $marks = DB::table('marks')->where('user_id', $viewer->id)->whereIn('dispatch_id', $ids)->get()
                ->groupBy('dispatch_id')->map(fn ($rows) => $rows->pluck('kind')->all())->all();
            $votes = DB::table('poll_votes')->where('user_id', $viewer->id)->whereIn('dispatch_id', $ids)
                ->pluck('poll_option_id', 'dispatch_id')->all();
        }

        return $dispatches->map(fn (Dispatch $d) => self::dispatch($d, $viewer, $marks[$d->id] ?? [], $votes[$d->id] ?? null))->values()->all();
    }

    /**
     * @param  array<int, string>  $marks
     * @return array<string, mixed>
     */
    protected static function dispatch(Dispatch $d, ?User $viewer, array $marks, ?int $myVote): array
    {
        // Deleted dispatches that others replied to stay as tombstones.
        if ($d->trashed() || ! $d->user) {
            return [
                'id' => $d->id, 'deleted' => true, 'type' => $d->type, 'body' => '', 'author' => null,
                'line' => ['key' => $d->line, 'name' => $d->lineName(), 'color' => $d->lineColor()],
                'stop' => null, 'parent_id' => $d->parent_id, 'created_at' => $d->created_at->toIso8601String(),
                'counts' => ['replies' => (int) $d->replies_count, 'quotes' => 0, 'been' => 0, 'going' => 0, 'mumbo' => 0, 'half_smokes' => 0],
                'viewer' => ['mine' => false, 'saved' => false, 'been' => false, 'going' => false, 'interested' => false, 'mumbo' => false],
            ];
        }

        $base = (new DispatchResource($d))->resolve(request());
        $showResults = $myVote !== null || $d->user_id === $viewer?->id || $d->isClosed() || ! $viewer;

        return [
            ...$base,
            'deleted' => false,
            'author' => self::user($d->user),
            'line' => ['key' => $d->line, 'name' => $d->lineName(), 'color' => $d->lineColor()],
            'stop' => $d->stop ? self::stop($d->stop) : null,
            'safety' => $d->safety,
            'place' => $d->place ? ['id' => $d->place->id, 'name' => $d->place->name, 'neighborhood' => $d->place->neighborhood] : null,
            'quoted' => $d->quoted?->user ? ['id' => $d->quoted->id, 'body' => $d->quoted->body, 'author' => self::user($d->quoted->user)] : null,
            'link' => $d->linkPreview ? ['url' => $d->linkPreview->url, 'title' => $d->linkPreview->title] : null,
            'poll' => $d->options->isEmpty() ? null : [
                'closes_at' => $d->closes_at?->toIso8601String(),
                'closed' => $d->isClosed(),
                'my_vote' => $myVote,
                'show_results' => $showResults,
                'options' => $d->options->map(fn ($o) => ['id' => $o->id, 'label' => $o->label, 'votes' => $showResults ? (int) $o->voters_count : null])->all(),
            ],
            'best_answer_id' => $d->best_answer_id,
            'hidden_by_author' => (bool) $d->hidden_by_author,
            'counts' => [
                'replies' => (int) $d->replies_count,
                'quotes' => (int) $d->quotes_count,
                'been' => (int) $d->been_count,
                'going' => (int) $d->going_count,
                'mumbo' => (int) $d->mumbo_count,
                'half_smokes' => (int) $d->half_smokes_count,
            ],
            'viewer' => [
                'mine' => $d->user_id === $viewer?->id,
                'saved' => in_array('save', $marks, true),
                'been' => in_array('been', $marks, true),
                'going' => in_array('going', $marks, true),
                'interested' => in_array('interested', $marks, true),
                'mumbo' => in_array('mumbo', $marks, true),
            ],
        ];
    }
}
