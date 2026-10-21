<?php

namespace App\Support;

use App\Models\Dispatch;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The live activity feed and the daily counts behind the admin charts.
 */
class Activity
{
    /**
     * Newest posts, sign-ups, reports and removals, mixed together.
     *
     * @return array<int, array{kind: string, at: string, text: string, username: string|null, url: string}>
     */
    public static function feed(int $limit = 40): array
    {
        $item = fn (string $kind, $at, string $text, ?string $username, string $url) => ['kind' => $kind, 'at' => $at?->toIso8601String(), 'text' => $text, 'username' => $username, 'url' => $url];

        return collect()
            ->concat(Dispatch::with('user')->where('status', '!=', 'scheduled')->latest('id')->take($limit)->get()
                ->map(fn ($d) => $item('post', $d->created_at, str($d->body)->limit(100)->toString(), $d->user?->username, route('dispatches.show', $d))))
            ->concat(User::where('is_bot', false)->latest('id')->take($limit)->get()
                ->map(fn ($u) => $item('signup', $u->created_at, 'Joined'.($u->risk_score ? ' · risk '.$u->risk_score : ''), $u->username, route('admin.users.show', $u))))
            ->concat(Report::with(['user', 'dispatch'])->latest('id')->take($limit)->get()
                ->map(fn ($r) => $item('report', $r->created_at, 'Reported for '.strtolower(config('stoop.report_reasons.'.$r->reason, $r->reason)).': '.str($r->dispatch?->body)->limit(80), $r->user?->username, $r->dispatch ? route('dispatches.show', $r->dispatch) : route('moderation'))))
            ->concat(Dispatch::with('user')->where('status', 'removed')->latest('updated_at')->take($limit)->get()
                ->map(fn ($d) => $item('removal', $d->updated_at, 'Removed: '.($d->moderation_reason ?: 'no reason given'), $d->user?->username, route('dispatches.show', $d))))
            ->sortByDesc('at')->take($limit)->values()->all();
    }

    /**
     * Posts, removals and sign-ups per day for the last 30 days, oldest first.
     *
     * @return array<int, array{day: string, posts: int, removals: int, signups: int}>
     */
    public static function days(int $days = 30): array
    {
        $since = now()->subDays($days - 1)->startOfDay();
        $count = fn ($query, string $column) => $query->where($column, '>=', $since)
            ->selectRaw("date($column) as day, count(*) as n")->groupBy('day')->pluck('n', 'day');

        $posts = $count(DB::table('dispatches')->where('status', '!=', 'scheduled'), 'created_at');
        $removals = $count(DB::table('dispatches')->where('status', 'removed'), 'updated_at');
        $signups = $count(DB::table('users')->where('is_bot', false), 'created_at');

        return collect(range($days - 1, 0))->map(function ($ago) use ($posts, $removals, $signups) {
            $day = now()->subDays($ago)->toDateString();

            return ['day' => $day, 'posts' => (int) ($posts[$day] ?? 0), 'removals' => (int) ($removals[$day] ?? 0), 'signups' => (int) ($signups[$day] ?? 0)];
        })->all();
    }
}
