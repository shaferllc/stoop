<?php

namespace App\Livewire;

use App\Models\Place;
use App\Models\PlaceDeal;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A place's current deal, and the form its verified owner posts deals with.
 */
class PlaceDealPanel extends Component
{
    #[Locked]
    public int $placeId;

    /** @var array<string, string> */
    public array $deal = ['title' => '', 'details' => '', 'starts_at' => '', 'ends_at' => ''];

    public bool $posting = false;

    public function post(): void
    {
        $place = Place::findOrFail($this->placeId);
        abort_unless(Auth::id() && $place->owner_id === Auth::id(), 403);

        $this->resetErrorBag();
        try {
            PlaceDeal::post(Auth::user(), $place, $this->deal);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError("deal.$field", $messages[0]);
            }

            return;
        }

        $this->reset('deal', 'posting');
        Flux::toast('Deal posted.');
    }

    public function end(int $id): void
    {
        $deal = PlaceDeal::where('place_id', $this->placeId)->findOrFail($id);
        abort_unless(Auth::id() && $deal->place->owner_id === Auth::id(), 403);

        $deal->update(['ends_at' => now()]);
        Flux::toast('Deal ended.');
    }

    public function render(): View
    {
        $place = Place::findOrFail($this->placeId);

        return view('livewire.place-deal-panel', [
            'place' => $place,
            'isOwner' => Auth::id() && $place->owner_id === Auth::id(),
            'active' => PlaceDeal::where('place_id', $place->id)->active()->first(),
            'upcoming' => PlaceDeal::where('place_id', $place->id)->where('starts_at', '>', now())->first(),
        ]);
    }
}
