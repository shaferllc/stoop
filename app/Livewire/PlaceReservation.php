<?php

namespace App\Livewire;

use App\Models\Place;
use App\Support\Audit;
use App\Support\Referrals;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A place's Reserve button, and the form its verified owner sets the reservation link with.
 */
class PlaceReservation extends Component
{
    #[Locked]
    public int $placeId;

    public bool $editing = false;

    public string $url = '';

    public function edit(): void
    {
        $this->url = (string) $this->ownPlace()->reservation_url;
        $this->editing = true;
    }

    public function save(): void
    {
        $place = $this->ownPlace();
        $this->validate(['url' => Referrals::rules()], [], ['url' => 'reservation link']);

        $place->update(['reservation_url' => trim($this->url) ?: null]);
        Audit::log('place.reservation_set', $place, ['url' => $place->reservation_url]);
        $this->reset('editing', 'url');
        Flux::toast('Reservation link saved.');
    }

    protected function ownPlace(): Place
    {
        $place = Place::findOrFail($this->placeId);
        abort_unless(Auth::id() && $place->owner_id === Auth::id(), 403);

        return $place;
    }

    public function render(): View
    {
        $place = Place::findOrFail($this->placeId);

        return view('livewire.place-reservation', [
            'place' => $place,
            'isOwner' => Auth::id() && $place->owner_id === Auth::id(),
        ]);
    }
}
