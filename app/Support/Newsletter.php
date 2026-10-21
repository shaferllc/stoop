<?php

namespace App\Support;

use App\Models\Dispatch;
use App\Models\Place;
use Illuminate\Support\Facades\DB;

/**
 * What goes in the weekly email: the week's best dispatches, poll results, the thread
 * everyone argued about, and the places people went.
 */
class Newsletter
{
    /**
     * @return array<string, mixed>
     */
    public static function digest(): array
    {
        $since = now()->subWeek();
        $week = Dispatch::topLevel()->visibleTo(null)->where('created_at', '>=', $since);

        $top = (clone $week)->forCards()->get()->sortByDesc(fn (Dispatch $d) => $d->rushScore() * 2 ** ($d->created_at->diffInMinutes(now()) / 180))->take(5)->values();

        $polls = (clone $week)->where('type', 'poll')->where('closes_at', '<=', now())
            ->with(['options' => fn ($q) => $q->withCount('voters')])->latest()->take(3)->get()
            ->map(fn ($poll) => [
                'question' => $poll->body,
                'url' => route('dispatches.show', $poll),
                'winner' => $poll->options->sortByDesc('voters_count')->first(),
                'votes' => $poll->options->sum('voters_count'),
            ])->filter(fn ($p) => $p['votes'] > 0)->values();

        $talk = (clone $week)->with('user')->withCount(['replies' => fn ($q) => $q->where('status', 'approved')])
            ->orderByDesc('replies_count')->first();

        $recent = fn ($q) => $q->where('created_at', '>=', $since)->where('status', 'approved');
        $places = Place::whereHas('dispatches', $recent)
            ->withCount(['dispatches as recent_count' => $recent])
            ->orderByDesc('recent_count')->take(3)->get();

        return [
            'week_of' => now(config('stoop.timezone'))->startOfWeek()->format('F j'),
            'top' => $top,
            'polls' => $polls,
            'talk' => $talk && $talk->replies_count > 0 ? $talk : null,
            'places' => $places,
            'new_people' => DB::table('users')->where('created_at', '>=', $since)->where('is_bot', false)->count(),
        ];
    }
}
