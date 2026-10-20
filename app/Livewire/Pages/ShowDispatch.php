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

    public function mount(Dispatch $dispatch): void
    {
        abort_unless(Dispatch::visibleTo(Auth::user())->whereKey($dispatch->id)->exists(), 404);

        $this->dispatchId = $dispatch->id;
    }

    #[On('dispatch-filed')]
    public function refreshAfterReply(): void {}

    public function render(): View
    {
        $user = Auth::user();
        $dispatch = Dispatch::visibleTo($user)->forCards()->with('parent.user')->find($this->dispatchId);

        abort_unless($dispatch, 404);

        $replies = $dispatch->replies()->visibleTo($user)->forCards()->oldest()->get();

        return view('livewire.pages.show-dispatch', [
            'dispatch' => $dispatch,
            'replies' => $replies,
            'state' => $this->viewerState($replies->concat([$dispatch])),
        ])->title(str($dispatch->body)->limit(50).' · '.$dispatch->user->username);
    }
}
