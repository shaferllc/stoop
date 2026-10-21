<?php

namespace App\Livewire\Admin;

use App\Models\CityEvent;
use App\Models\MeetupSponsor;
use App\Models\Place;
use App\Support\Audit;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Sales: a host venue sponsor for a Stoop Sits meetup, on the Moments page. One per meetup.
 */
class MeetupSponsors extends Component
{
    use StaffOnly;

    protected const ROLE = 'sales';

    /** @var array<string, mixed> */
    public array $form = ['event_id' => '', 'place_id' => '', 'offer' => '', 'url' => ''];

    public function save(): void
    {
        $data = $this->validate([
            'form.event_id' => ['required', Rule::exists('events', 'id')->where('kind', 'meetup')],
            'form.place_id' => ['required', 'exists:places,id'],
            'form.offer' => ['nullable', 'string', 'max:120'],
            'form.url' => ['required', 'url:https', 'max:300'],
        ], [], ['form.event_id' => 'meetup', 'form.place_id' => 'venue', 'form.url' => 'link'])['form'];

        $sponsor = MeetupSponsor::updateOrCreate(['event_id' => $data['event_id']], ['place_id' => $data['place_id'], 'offer' => $data['offer'] ?: null, 'url' => $data['url']]);
        Audit::log('meetup_sponsor.saved', $sponsor);
        $this->reset('form');
        Flux::toast('Host venue saved.');
    }

    public function remove(int $id): void
    {
        $sponsor = MeetupSponsor::findOrFail($id);
        Audit::log('meetup_sponsor.removed', $sponsor);
        $sponsor->delete();
    }

    public function render(): View
    {
        return view('livewire.admin.meetup-sponsors', [
            'meetups' => CityEvent::where('kind', 'meetup')->where('ends_at', '>=', now())->orderBy('starts_at')->get(['id', 'name', 'starts_at']),
            // ponytail: every place in one select; swap for a search box if the list gets long
            'places' => Place::orderBy('name')->get(['id', 'name', 'neighborhood']),
            'sponsors' => MeetupSponsor::with('event', 'place')->withCount(['redemptions as redeemed_count' => fn ($q) => $q->whereNotNull('redeemed_at')])->latest()->take(15)->get(),
        ]);
    }
}
