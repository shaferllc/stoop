<?php

namespace App\Livewire\Pages;

use App\Models\Challenge;
use App\Models\Place;
use App\Support\Challenges;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A list challenge: the player's progress, the places on the list (checked ones marked)
 * and the leaderboard.
 */
#[Layout('layouts::stoop')]
class ChallengeShow extends Component
{
    #[Locked]
    public int $challengeId;

    public function mount(Challenge $challenge): void
    {
        $this->challengeId = $challenge->id;
    }

    public function render(): View
    {
        $challenge = Challenge::findOrFail($this->challengeId);
        // Catches finishes the hourly sweep hasn't recorded yet.
        if ($user = Auth::user()) {
            Challenges::check($user);
        }

        return view('livewire.pages.challenge-show', [
            'challenge' => $challenge,
            'progress' => Challenges::progress($challenge, $user),
            'places' => Place::where('list_name', $challenge->list_name)->orderBy('list_rank')->orderBy('name')->get(['id', 'name', 'slug', 'stop', 'neighborhood', 'list_rank']),
            'leaders' => Challenges::leaderboard($challenge),
        ])->title($challenge->title);
    }
}
