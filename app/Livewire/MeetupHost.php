<?php

namespace App\Livewire;

use App\Models\MeetupRedemption;
use App\Models\MeetupSponsor;
use App\Models\Place;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * On a place page, for its owner: the Stoop Sits meetups it hosts as a sponsor (RSVPs,
 * check-ins, offers redeemed), and marking an attendee's offer code as used.
 */
class MeetupHost extends Component
{
    #[Locked]
    public int $placeId;

    public string $code = '';

    public function mount(int $placeId): void
    {
        $this->placeId = $placeId;
        $this->place();
    }

    public function redeem(): void
    {
        $place = $this->place();

        if (! RateLimiter::attempt('redeem:'.Auth::id(), 20, fn () => true)) {
            $this->addError('code', 'Too many tries. Wait a minute.');

            return;
        }

        $redemption = MeetupRedemption::where('code', strtoupper(trim($this->code)))
            ->whereHas('sponsor', fn ($q) => $q->where('place_id', $place->id))->first();

        if (! $redemption) {
            $this->addError('code', 'That code isn’t for one of your meetups.');

            return;
        }

        // One update that only matches an unused code, so two taps can't both redeem it.
        if (! MeetupRedemption::whereKey($redemption->id)->whereNull('redeemed_at')->update(['redeemed_at' => now()])) {
            $this->addError('code', 'That code was already used.');

            return;
        }

        $this->reset('code');
        Flux::toast('Marked as redeemed.');
    }

    protected function place(): Place
    {
        $place = Place::findOrFail($this->placeId);
        abort_unless(Auth::id() && $place->owner_id === Auth::id(), 403);

        return $place;
    }

    public function render(): View
    {
        return view('livewire.meetup-host', [
            'meetups' => MeetupSponsor::with('event')->where('place_id', $this->place()->id)
                ->withCount(['redemptions as redeemed_count' => fn ($q) => $q->whereNotNull('redeemed_at')])->latest()->get()
                ->each(fn (MeetupSponsor $m) => $m->event->loadCount(['rsvps', 'attendees'])),
        ]);
    }
}
