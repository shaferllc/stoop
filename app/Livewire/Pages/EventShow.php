<?php

namespace App\Livewire\Pages;

use App\Livewire\Concerns\HandlesDispatchActions;
use App\Models\CityEvent;
use App\Models\Dispatch;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
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

    public function render(): View
    {
        $event = CityEvent::withCount('attendees')->findOrFail($this->eventId);
        $user = Auth::user();

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
            'checkedIn' => $user ? $event->attendees()->whereKey($user->id)->exists() : false,
            'state' => $this->viewerState($dispatches),
        ])->title($event->name);
    }
}
