<?php

namespace App\Livewire\Settings;

use App\Support\Sports;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Favorite DC teams, in profile settings. They show as small badges on the profile.
 */
class FavoriteTeams extends Component
{
    /** @var array<int, string> */
    public array $teams = [];

    public function mount(): void
    {
        $this->teams = Sports::favorites(Auth::user());
    }

    public function save(): void
    {
        $this->validate(['teams' => ['array'], 'teams.*' => [Rule::in(array_keys(Sports::teams()))]]);
        $this->teams = Sports::setFavorites(Auth::user(), $this->teams);
        Flux::toast(variant: 'success', text: __('Teams saved.'));
    }

    public function render(): View
    {
        return view('livewire.settings.favorite-teams', ['all' => Sports::teams()]);
    }
}
