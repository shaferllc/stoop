<?php

namespace App\Livewire\Pages;

use App\Livewire\Concerns\HandlesDispatchActions;
use App\Models\CityEvent;
use App\Models\Dispatch;
use App\Models\MeetupRedemption;
use App\Support\Ads;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts::stoop')]
class EventShow extends Component
{
    use HandlesDispatchActions;

    #[Locked]
    public int $eventId;

    public string $code = '';

    public function mount(CityEvent $event): void
    {
        $this->eventId = $event->id;
    }

    /**
     * Stoop Sits meetups: enter the code shared at the meetup to check in.
     */
    public function checkIn(): void
    {
        if (! $user = $this->actor()) {
            return;
        }

        $event = CityEvent::findOrFail($this->eventId);

        if (! RateLimiter::attempt('checkin:'.$user->id, 10, fn () => true)) {
            $this->addError('code', 'Too many tries. Wait a minute.');

            return;
        }

        if (! $event->checkin_code || ! hash_equals(strtoupper($event->checkin_code), strtoupper(trim($this->code)))) {
            $this->addError('code', 'That’s not the code. Ask whoever’s hosting.');

            return;
        }

        if ($event->starts_at->subHour()->isFuture() || $event->ends_at->addHours(2)->isPast()) {
            $this->addError('code', 'Check-in opens an hour before and closes two hours after.');

            return;
        }

        $event->attendees()->syncWithoutDetaching([$user->id]);
        $this->reset('code');
        Flux::toast('Checked in. Good to see you on the stoop.');
    }

    /**
     * Stoop Sits meetups: say you're going, or take it back.
     */
    public function rsvp(): void
    {
        if (! $user = $this->actor()) {
            return;
        }

        $event = CityEvent::where('kind', 'meetup')->findOrFail($this->eventId);
        if ($event->ends_at->isPast() || ! RateLimiter::attempt('rsvp:'.$user->id, 20, fn () => true)) {
            return;
        }

        $event->rsvps()->toggle([$user->id]);
    }

    public function render(): View
    {
        $event = CityEvent::withCount(['attendees', 'rsvps'])->with('sponsor.place')->findOrFail($this->eventId);
        $user = Auth::user();
        $checkedIn = $user ? $event->attendees()->whereKey($user->id)->exists() : false;

        // A sponsored meetup: count the impression, and give checked-in attendees their offer code.
        $sponsor = $event->kind === 'meetup' ? $event->sponsor : null;
        if ($sponsor) {
            Ads::record($sponsor);
        }
        $redemption = $sponsor?->offer && $checkedIn
            ? MeetupRedemption::firstOrCreate(['meetup_sponsor_id' => $sponsor->id, 'user_id' => $user->id], ['code' => Str::upper(Str::random(8))])
            : null;

        $dispatches = Dispatch::topLevel()->visibleTo($user)->forCards()->where('event_id', $event->id)->latest()->take(100)->get();

        // Posts grouped by stop, with the average rating where the event has one.
        $byStop = $dispatches->whereNotNull('stop')->groupBy('stop')->map(fn ($posts, $stop) => [
            'stop' => $stop,
            'count' => $posts->count(),
            'rating' => $event->rating_label ? round((float) $posts->pluck('details.rating')->filter()->avg(), 1) : null,
        ])->sortByDesc(fn ($row) => [$row['rating'] ?? 0, $row['count']])->values();

        $passport = null;
        if ($event->place_list && $user) {
            $been = DB::table('marks')->join('dispatches', 'dispatches.id', '=', 'marks.dispatch_id')
                ->join('places', 'places.id', '=', 'dispatches.place_id')
                ->where('marks.user_id', $user->id)->where('marks.kind', 'been')->where('places.list_name', $event->place_list)
                ->whereBetween('marks.created_at', [$event->starts_at, $event->ends_at])->distinct()->count('places.id');
            $passport = ['been' => $been, 'size' => DB::table('places')->where('list_name', $event->place_list)->count()];
        }

        return view('livewire.pages.event-show', [
            'event' => $event,
            'dispatches' => $dispatches,
            'byStop' => $byStop,
            'passport' => $passport,
            'checkedIn' => $checkedIn,
            'going' => $user && $event->kind === 'meetup' && $event->rsvps()->whereKey($user->id)->exists(),
            'sponsor' => $sponsor,
            'redemption' => $redemption,
            'state' => $this->viewerState($dispatches),
        ])->title($event->name);
    }
}
