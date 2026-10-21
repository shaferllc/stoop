<?php

namespace App\Livewire\Pages;

use App\Models\Hunt;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::stoop')]
#[Title('Scavenger hunts')]
class HuntsIndex extends Component
{
    public function render(): View
    {
        return view('livewire.pages.hunts', [
            'running' => Hunt::running()->withCount('steps')->orderBy('ends_at')->get(),
            'upcoming' => Hunt::where('starts_at', '>', now())->withCount('steps')->orderBy('starts_at')->take(5)->get(),
            'past' => Hunt::where('ends_at', '<', now())->withCount('steps')->latest('ends_at')->take(10)->get(),
        ]);
    }
}
