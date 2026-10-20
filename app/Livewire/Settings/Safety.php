<?php

namespace App\Livewire\Settings;

use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Safety settings')]
class Safety extends Component
{
    public string $repliesFrom = 'everyone';

    public function mount(): void
    {
        $this->repliesFrom = Auth::user()->replies_from ?? 'everyone';
    }

    public function updatedRepliesFrom(): void
    {
        $this->validate(['repliesFrom' => [Rule::in(['everyone', 'following'])]]);
        Auth::user()->forceFill(['replies_from' => $this->repliesFrom])->save();
        Flux::toast('Saved.');
    }

    public function undo(int $targetId, string $type): void
    {
        DB::table('silences')->where(['user_id' => Auth::id(), 'target_id' => $targetId, 'type' => $type])->delete();
        Flux::toast($type === 'block' ? 'Unblocked.' : 'Unmuted.');
    }

    public function render(): View
    {
        return view('livewire.settings.safety', [
            'silenced' => Auth::user()->silenced()->orderBy('silences.created_at', 'desc')->get(),
            'penalties' => Auth::user()->penalties()->latest()->get(),
        ]);
    }
}
