<?php

namespace App\Livewire\Pages;

use App\Livewire\Concerns\HandlesDispatchActions;
use App\Models\Dispatch;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::stoop')]
#[Title('Saved')]
class Saved extends Component
{
    use HandlesDispatchActions;

    public function render(): View
    {
        $user = Auth::user();

        $dispatches = Dispatch::visibleTo($user)->forCards()
            ->join('marks', fn ($j) => $j->on('marks.dispatch_id', '=', 'dispatches.id')
                ->where('marks.user_id', $user->id)->where('marks.kind', 'save'))
            ->orderByDesc('marks.created_at')
            ->select('dispatches.*')->get();

        return view('livewire.pages.saved', [
            'dispatches' => $dispatches,
            'state' => $this->viewerState($dispatches),
        ]);
    }
}
