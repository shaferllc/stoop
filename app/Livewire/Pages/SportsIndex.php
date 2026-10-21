<?php

namespace App\Livewire\Pages;

use App\Models\Game;
use App\Support\Sports;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::stoop')]
#[Title('Sports')]
class SportsIndex extends Component
{
    public function render(): View
    {
        $today = Sports::today();
        $endOfToday = now(config('stoop.timezone'))->endOfDay()->utc();

        return view('livewire.pages.sports', [
            'today' => $today,
            'live' => $today->contains(fn (Game $g) => $g->isLive()),
            'week' => Game::whereNotNull('team_key')->whereBetween('starts_at', [$endOfToday, $endOfToday->copy()->addDays(7)])->orderBy('starts_at')->get(),
            'teams' => Sports::teams(),
            'fans' => Sports::fanCounts(),
            'boards' => collect(Sports::teams())->pluck('league')->unique()
                ->mapWithKeys(fn ($league) => [$league => Sports::leaderboard($league, 5)])->filter(fn ($rows) => $rows->isNotEmpty()),
        ]);
    }
}
