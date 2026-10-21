<?php

namespace App\Livewire\Pages;

use App\Livewire\Concerns\HandlesDispatchActions;
use App\Models\Dispatch;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts::stoop')]
class ShowDispatch extends Component
{
    use HandlesDispatchActions;

    /** Nested replies shown on one page; deeper ones get a "Continue this thread" link. */
    public const MAX_DEPTH = 5;

    public const MAX_REPLIES = 400;

    public int $dispatchId;

    public function mount(int $dispatch): void
    {
        $found = Dispatch::withTrashed()->findOrFail($dispatch);

        abort_unless(
            Dispatch::visibleTo(Auth::user())->whereKey($found->id)->exists() || ($found->trashed() && $found->replies()->exists()),
            404,
        );

        $this->dispatchId = $found->id;
    }

    #[On('dispatch-filed')]
    public function refreshAfterReply(): void {}

    public function render(): View
    {
        $user = Auth::user();
        $dispatch = Dispatch::withTrashed()->forCards()->with('parent.user')->find($this->dispatchId);

        abort_unless($dispatch, 404);

        // The whole conversation, a level at a time. The Best Answer goes first among direct replies.
        $children = collect();
        $frontier = [$dispatch->id];
        for ($depth = 0; $frontier && $depth < self::MAX_DEPTH && $children->flatten()->count() < self::MAX_REPLIES; $depth++) {
            $level = Dispatch::whereIn('parent_id', $frontier)->visibleTo($user)->forCards()->with('parent')->oldest()->get();
            $children = $children->union($level->groupBy('parent_id'));
            $frontier = $level->pluck('id')->all();
        }
        $replies = $children->flatten();

        // How many replies sit under each reply, deepest first so parents can add up their children.
        $counts = [];
        foreach ($replies->reverse() as $reply) {
            $counts[$reply->parent_id] = ($counts[$reply->parent_id] ?? 0) + 1 + ($counts[$reply->id] ?? 0);
        }
        if ($children->has($dispatch->id)) {
            $children[$dispatch->id] = $children[$dispatch->id]->sortBy(fn ($r) => $r->id === $dispatch->best_answer_id ? 0 : 1)->values();
        }

        $title = $dispatch->trashed() ? 'Deleted dispatch' : str($dispatch->body)->limit(50).' · @'.$dispatch->user->username;

        return view('livewire.pages.show-dispatch', [
            'dispatch' => $dispatch,
            'replies' => $replies,
            'children' => $children,
            'counts' => $counts,
            'maxDepth' => self::MAX_DEPTH,
            'state' => $this->viewerState($replies->concat([$dispatch])),
        ])->title($title)->layoutData([
            'description' => $dispatch->trashed() ? null : str($dispatch->body)->limit(160)->toString(),
            'ogImage' => $dispatch->trashed() ? null : route('dispatches.og', $dispatch),
        ]);
    }
}
