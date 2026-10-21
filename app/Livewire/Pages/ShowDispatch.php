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

        // The Best Answer goes first, then everything else in order.
        $replies = $dispatch->replies()->visibleTo($user)->forCards()->with('parent')->oldest()->get()
            ->sortBy(fn ($r) => $r->id === $dispatch->best_answer_id ? 0 : 1)->values();

        $title = $dispatch->trashed() ? 'Deleted dispatch' : str($dispatch->body)->limit(50).' · @'.$dispatch->user->username;

        return view('livewire.pages.show-dispatch', [
            'dispatch' => $dispatch,
            'replies' => $replies,
            'state' => $this->viewerState($replies->concat([$dispatch])),
        ])->title($title)->layoutData([
            'description' => $dispatch->trashed() ? null : str($dispatch->body)->limit(160)->toString(),
            'ogImage' => $dispatch->trashed() ? null : route('dispatches.og', $dispatch),
        ]);
    }
}
