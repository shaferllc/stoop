<?php

namespace App\Livewire\Pages;

use App\Models\Place;
use App\Models\Promotion;
use App\Support\Business;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A Business owner's numbers for one of their places over the last 30 days, and the delivery
 * of their promotions there.
 */
#[Layout('layouts::stoop')]
class BusinessAnalytics extends Component
{
    #[Locked]
    public int $placeId;

    public function mount(Place $place): void
    {
        abort_unless($place->owner_id === Auth::id(), 403);
        $this->placeId = $place->id;
    }

    public function render(): View
    {
        $place = Place::findOrFail($this->placeId);
        $isBusiness = Business::isBusiness(Auth::user());

        return view('livewire.pages.business-analytics', [
            'place' => $place,
            'isBusiness' => $isBusiness,
            'rows' => $isBusiness ? Business::analytics($place) : collect(),
            'promotions' => Promotion::with('ad')->where('user_id', Auth::id())->where('place_id', $place->id)->where('status', '!=', 'unpaid')->latest()->get(),
        ])->title('Analytics · '.$place->name);
    }
}
