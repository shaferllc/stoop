<?php

namespace App\Livewire\Pages;

use App\Livewire\Concerns\HandlesDispatchActions;
use App\Models\Dispatch;
use App\Models\DispatchImage;
use App\Models\Place;
use App\Models\PlaceClaim;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts::stoop')]
class PlaceShow extends Component
{
    use HandlesDispatchActions;

    #[Locked]
    public int $placeId;

    public bool $claiming = false;

    public string $claimRole = '';

    public string $claimEvidence = '';

    public function mount(Place $place): void
    {
        $this->placeId = $place->id;
    }

    #[On('dispatch-filed')]
    public function refreshAfterPost(): void {}

    /**
     * Ask to manage this place. Staff check the evidence and approve it in the admin.
     */
    public function claim(): void
    {
        if (! $user = $this->actor()) {
            return;
        }

        $this->validate(['claimRole' => ['required', 'string', 'max:80'], 'claimEvidence' => ['required', 'string', 'min:10', 'max:500']]);
        $place = Place::findOrFail($this->placeId);

        if ($place->owner_id || $place->claims()->where('user_id', $user->id)->where('status', 'pending')->exists()) {
            $this->addError('claimEvidence', 'There’s already a claim in for this place.');

            return;
        }

        PlaceClaim::create(['place_id' => $place->id, 'user_id' => $user->id, 'role' => $this->claimRole, 'evidence' => $this->claimEvidence]);
        $this->reset('claiming', 'claimRole', 'claimEvidence');
        Flux::toast('Claim sent. Our team will check it and get back to you.');
    }

    public function render(): View
    {
        $place = Place::with('owner')->findOrFail($this->placeId);
        $viewer = Auth::user();

        $dispatches = Dispatch::topLevel()->visibleTo($viewer)->forCards()->where('place_id', $place->id)->latest()->take(50)->get();
        $ids = Dispatch::visibleTo(null)->where('place_id', $place->id)->pluck('id');

        return view('livewire.pages.place-show', [
            'place' => $place,
            'dispatches' => $dispatches,
            'state' => $this->viewerState($dispatches),
            'beenCount' => DB::table('marks')->whereIn('dispatch_id', $ids)->where('kind', 'been')->distinct()->count('user_id'),
            'photos' => DispatchImage::whereIn('dispatch_id', $ids)->latest('id')->take(9)->get(),
        ])->title($place->name)->layoutData(['description' => collect([$place->category, $place->neighborhood, $place->listLabel()])->filter()->join(' · ')]);
    }
}
