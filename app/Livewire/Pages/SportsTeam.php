<?php

namespace App\Livewire\Pages;

use App\Models\Game;
use App\Models\User;
use App\Support\Sports;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts::stoop')]
class SportsTeam extends Component
{
    #[Locked]
    public string $team = '';

    public function mount(string $team): void
    {
        abort_unless(isset(Sports::teams()[$team]), 404);
        $this->team = $team;
    }

    public function toggleFan(): void
    {
        if (! $user = Auth::user()) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        $mine = Sports::favorites($user);
        Sports::setFavorites($user, in_array($this->team, $mine, true) ? array_diff($mine, [$this->team]) : [...$mine, $this->team]);
    }

    public function render(): View
    {
        $config = Sports::teams()[$this->team];
        $games = Game::where('team_key', $this->team);

        return view('livewire.pages.sports-team', [
            'config' => $config,
            'live' => (clone $games)->where('status', 'live')->get(),
            'upcoming' => (clone $games)->where('status', 'pre')->where('starts_at', '>', now())->orderBy('starts_at')->take(10)->get(),
            'results' => (clone $games)->where('status', 'final')->latest('starts_at')->take(10)->get(),
            'threads' => (clone $games)->whereNotNull('dispatch_id')->latest('starts_at')->take(10)->get(),
            'standing' => Sports::standings($this->team),
            'fanCount' => Sports::fanCounts()[$this->team] ?? 0,
            'fans' => User::whereIn('id', fn ($q) => $q->select('user_id')->from('team_fans')->where('team_key', $this->team))
                ->where('is_bot', false)->latest('id')->take(24)->get(),
            'isFan' => Auth::check() && in_array($this->team, Sports::favorites(Auth::user()), true),
        ])->title($config['full']);
    }
}
