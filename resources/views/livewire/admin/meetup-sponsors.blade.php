<section class="mt-3 rounded-xl border border-rule bg-card p-4 text-sm">
    @php($input = 'mt-0.5 block w-full rounded-md border border-rule bg-paper px-2 py-1')
    <h2 class="font-semibold">Sponsored meetups</h2>
    <p class="text-mute">A host venue for a Stoop Sits meetup. The meetup page and its calendar card say “Hosted by …”, labeled Sponsored. People who check in get a code for the offer, and the venue’s owner marks codes used on their place page.</p>
    <form wire:submit="save" class="mt-2 grid gap-2 sm:grid-cols-2">
        <label>Meetup<select wire:model="form.event_id" class="{{ $input }}"><option value="">Pick one</option>@foreach ($meetups as $m)<option value="{{ $m->id }}">{{ $m->name }} · {{ $m->starts_at->timezone(config('stoop.timezone'))->format('M j') }}</option>@endforeach</select>@error('form.event_id')<span class="text-flag">{{ $message }}</span>@enderror</label>
        <label>Host venue<select wire:model="form.place_id" class="{{ $input }}"><option value="">Pick one</option>@foreach ($places as $p)<option value="{{ $p->id }}">{{ $p->name }}{{ $p->neighborhood ? ' · '.$p->neighborhood : '' }}</option>@endforeach</select>@error('form.place_id')<span class="text-flag">{{ $message }}</span>@enderror</label>
        <label>Offer (optional)<input wire:model="form.offer" maxlength="120" placeholder="First round half off" class="{{ $input }}">@error('form.offer')<span class="text-flag">{{ $message }}</span>@enderror</label>
        <label>Sponsor link<input wire:model="form.url" placeholder="https://" class="{{ $input }}">@error('form.url')<span class="text-flag">{{ $message }}</span>@enderror</label>
        <div class="sm:col-span-2"><button class="rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Save host venue</button></div>
    </form>

    @if ($sponsors->isNotEmpty())
        <ul class="mt-3 divide-y divide-rule">
            @foreach ($sponsors as $s)
                <li wire:key="meetup-sponsor-{{ $s->id }}" class="flex flex-wrap items-center gap-2 py-2">
                    <b>{{ $s->place->name }}</b> <span class="text-mute">hosts {{ $s->event->name }}{{ $s->offer ? ' · '.$s->offer : '' }} · {{ $s->redeemed_count }} redeemed</span>
                    <span class="ml-auto whitespace-nowrap">
                        <a href="{{ route('admin.ads', ['report' => 'meetup:'.$s->id]) }}" class="underline">Report</a>
                        <button type="button" wire:click="remove({{ $s->id }})" wire:confirm="Remove this host venue?" class="ml-2 underline">Remove</button>
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</section>
