<div class="space-y-3">
    <section class="rounded-xl border border-rule bg-card p-5">
        <div class="text-[11.5px] font-semibold uppercase tracking-wide text-flag">{{ $event->kind === 'meetup' ? 'Stoop Sits' : ($event->isHappening() ? 'Happening now' : ($event->starts_at->isFuture() ? 'Coming up' : 'Past event')) }}</div>
        <h1 class="font-display text-3xl font-bold">{{ $event->name }}</h1>
        <p class="text-sm text-mute">{{ $event->starts_at->timezone(config('stoop.timezone'))->format('D M j, g:ia') }} to {{ $event->ends_at->timezone(config('stoop.timezone'))->format('D M j, g:ia') }}@if ($event->stop) · <x-station :code="$event->stop" />@endif</p>
        @if ($event->description)<p class="mt-2 max-w-prose whitespace-pre-line">{{ $event->description }}</p>@endif
        @if ($sponsor)
            <p class="mt-2 text-sm" data-meetup-sponsor><span class="mr-1 rounded border border-rule px-1 text-[10px] font-bold uppercase text-mute">Sponsored</span>
                Hosted by <a href="{{ route('ads.click', ['meetup', $sponsor->id]) }}" target="_blank" rel="sponsored noopener" class="font-semibold underline">{{ $sponsor->place->name }}</a>
                · <a href="{{ route('places.show', $sponsor->place) }}" wire:navigate class="text-mute hover:underline">See the place</a>
                @if ($sponsor->offer)<br><span class="text-mute">For people who check in: {{ $sponsor->offer }}</span>@endif
            </p>
        @endif

        @if ($event->kind === 'meetup')
            <div class="mt-3 text-sm">
                <b>{{ $event->attendees_count }}</b> {{ str('neighbor')->plural($event->attendees_count) }} checked in · <b>{{ $event->rsvps_count }}</b> going.
                @auth
                    @if (! $event->ends_at->isPast())
                        <button type="button" wire:click="rsvp" @class(['ml-2 rounded-full px-2.5 py-0.5 text-[12px] font-semibold', 'bg-ink text-paper' => $going, 'border border-rule' => ! $going])>{{ $going ? 'Going ✓' : 'I’m going' }}</button>
                    @endif
                    @if ($checkedIn)
                        <span class="ml-2 rounded-full bg-flag px-2 py-0.5 text-[12px] font-bold text-white">You were here</span>
                        @if ($redemption)
                            <p class="mt-2" data-offer-code>Your code for {{ $sponsor->offer }} at {{ $sponsor->place->name }}: <b class="font-mono text-base">{{ $redemption->code }}</b>{{ $redemption->redeemed_at ? ' (used)' : '. Show it to the staff there.' }}</p>
                        @endif
                    @else
                        <form wire:submit="checkIn" class="mt-2 flex gap-2">
                            <input wire:model="code" maxlength="20" placeholder="Check-in code" aria-label="Check-in code" class="w-40 rounded-md border border-rule bg-paper px-2.5 py-1.5 font-mono uppercase">
                            <button class="rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Check in</button>
                        </form>
                        @error('code') <p class="mt-1 text-flag">{{ $message }}</p> @enderror
                    @endif
                @endauth
            </div>
        @elseif ($event->isHappening())
            <p class="mt-3 text-sm">Post from it: pick “{{ $event->name }}” under “Happening now” in the composer{{ $event->rating_label ? ' and add your '.strtolower($event->rating_label) : '' }}.</p>
        @endif

        @if ($passport)
            <p class="mt-3 text-sm">Your passport: <b>{{ $passport['been'] }}</b> of {{ $passport['size'] }} places from {{ $event->place_list }} during {{ $event->name }}.</p>
        @endif
    </section>

    @if ($byStop->isNotEmpty())
        <section class="rounded-xl border border-rule bg-card p-4">
            <h2 class="text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">{{ $event->rating_label ? 'Best rated, by stop' : 'Where people are' }}</h2>
            <ol class="mt-2 space-y-1.5 text-sm">
                @foreach ($byStop as $row)
                    <li class="flex items-center gap-3">
                        <a href="{{ route('stops.show', $row['stop']) }}" class="w-44 shrink-0"><x-station :code="$row['stop']" /></a>
                        @if ($row['rating'])<span class="text-flag">{{ str_repeat('★', (int) round($row['rating'])) }}</span><span class="text-mute">{{ $row['rating'] }}</span>@endif
                        <span class="ml-auto text-mute">{{ $row['count'] }} {{ str('post')->plural($row['count']) }}</span>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    @forelse ($dispatches as $dispatch)
        <x-dispatch-card :dispatch="$dispatch" :translated="$translations[$dispatch->id] ?? null" :state="$state" />
    @empty
        @if ($event->kind !== 'meetup')
            <x-empty title="No posts yet" art="platform">{{ $event->isHappening() ? 'Be the first to post from '.$event->name.'.' : 'Posts show up here while it’s happening.' }}</x-empty>
        @endif
    @endforelse

    @include('partials.report-dialog')
    @include('partials.undo-bar')
</div>
