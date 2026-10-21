<?php

namespace App\Livewire\Pages;

use App\Models\Challenge;
use App\Support\Challenges;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::stoop')]
#[Title('Challenges')]
class ChallengesIndex extends Component
{
    public function render(): View
    {
        $running = Challenge::running()->orderBy('ends_at')->get();

        return view('livewire.pages.challenges', [
            'running' => $running,
            'upcoming' => Challenge::where('starts_at', '>', now())->orderBy('starts_at')->take(5)->get(),
            'past' => Challenge::where('ends_at', '<', now())->latest('ends_at')->take(10)->get(),
            'progress' => $running->mapWithKeys(fn (Challenge $c) => [$c->id => Challenges::progress($c, Auth::user())]),
        ]);
    }
}
