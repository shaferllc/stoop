<?php

namespace App\Livewire\Pages;

use App\Models\Contest;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts::stoop')]
class ContestShow extends Component
{
    #[Locked]
    public int $contestId;

    public string $guess = '';

    public function mount(Contest $contest): void
    {
        $this->contestId = $contest->id;
        $this->guess = (string) $contest->entries()->where('user_id', Auth::id())->first()?->guess?->toDateString();
    }

    public function enter(): void
    {
        abort_unless(Auth::check(), 403);
        $contest = Contest::findOrFail($this->contestId);

        if (! $contest->isOpen()) {
            $this->addError('guess', 'Guessing is closed.');

            return;
        }

        $this->validate(['guess' => ['required', 'date', 'after:today', 'before:'.now()->addYear()->toDateString()]]);
        $contest->entries()->updateOrCreate(['user_id' => Auth::id()], ['guess' => $this->guess]);
        Flux::toast('Your guess is in. You can change it until guessing closes.');
    }

    public function render(): View
    {
        $contest = Contest::withCount('entries')->findOrFail($this->contestId);
        $standings = $contest->standings();

        return view('livewire.pages.contest-show', [
            'contest' => $contest,
            'standings' => $standings,
            'mine' => $standings->search(fn ($e) => $e->user_id === Auth::id()),
        ])->title($contest->title);
    }
}
