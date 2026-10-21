@php($tz = config('stoop.timezone'))
<div class="space-y-3">
    <div>
        <h1 class="font-display text-3xl font-bold">Happenings</h1>
        <p class="text-mute">What neighbors are hosting and going to, by day. Post a Happening with a start time to put it on the calendar.</p>
    </div>

    <div class="flex flex-wrap items-center gap-2 text-sm">
        <div class="flex overflow-hidden rounded-lg border border-rule" role="group" aria-label="Range">
            @foreach (['week' => 'Week', 'month' => 'Month'] as $key => $label)
                <button type="button" wire:click="setView('{{ $key }}')" @class(['px-3 py-1', 'bg-ink text-paper' => $view === $key])>{{ $label }}</button>
            @endforeach
        </div>
        <button type="button" wire:click="$set('offset', {{ $offset - 1 }})" class="rounded-lg border border-rule px-2 py-1" aria-label="Earlier">‹</button>
        <span class="font-semibold">{{ $view === 'month' ? $from->format('F Y') : $from->format('M j').' – '.$to->format('M j') }}</span>
        <button type="button" wire:click="$set('offset', {{ $offset + 1 }})" class="rounded-lg border border-rule px-2 py-1" aria-label="Later">›</button>
        <select wire:model.live="line" aria-label="Line" class="ml-auto rounded-md border border-rule bg-paper px-1.5 py-1 text-[13px]">
            <option value="">All lines</option>
            @foreach (config('stoop.lines') as $key => $l)<option value="{{ $key }}">{{ $l['name'] }}</option>@endforeach
        </select>
        <select wire:model.live="stop" aria-label="Stop" class="rounded-md border border-rule bg-paper px-1.5 py-1 text-[13px]">
            <option value="">All stops</option>
            @foreach (collect(config('stoop.stations'))->sort() as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@endforeach
        </select>
    </div>

    @forelse ($days as $day)
        <section wire:key="day-{{ $day['day']->toDateString() }}">
            <h2 class="mb-1.5 text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">{{ $day['day']->isToday() ? 'Today' : ($day['day']->isTomorrow() ? 'Tomorrow' : $day['day']->format('l, M j')) }}</h2>
            <ul class="divide-y divide-rule rounded-xl border border-rule bg-card">
                @foreach ($day['events'] as $e)
                    <li class="flex items-center gap-3 px-4 py-2.5 text-sm">
                        <span class="w-16 shrink-0 text-mute">All day</span>
                        <a href="{{ route('events.show', $e) }}" wire:navigate class="font-semibold hover:underline">{{ $e->name }}</a>
                        <span class="rounded-full bg-chip px-2 text-[11.5px] text-mute">City event</span>
                    </li>
                @endforeach
                @foreach ($day['happenings'] as $d)
                    @php($marks = $state['marks'][$d->id] ?? [])
                    <li wire:key="h-{{ $d->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5 text-sm">
                        <span class="w-16 shrink-0 font-semibold tabular-nums">{{ $d->happening->starts_at->timezone($tz)->format('g:ia') }}</span>
                        <div class="min-w-0 flex-1">
                            @if ($d->happening->featured)<span class="mr-1 rounded bg-flag px-1 text-[10px] font-bold text-white">FEATURED</span>@endif
                            @if ($d->happening->isTicketed())<span class="mr-1 rounded border border-rule px-1 text-[10px] font-bold text-mute">TICKETED{{ $d->happening->priceLabel() ? ' · '.$d->happening->priceLabel() : '' }}</span>@endif
                            <a href="{{ route('dispatches.show', $d) }}" wire:navigate class="hover:underline">{{ str($d->body)->limit(90) }}</a>
                            <div class="text-[12.5px] text-mute">
                                {{ '@'.$d->user->username }}
                                @if ($d->stop) · {{ $d->stopName() }} @endif
                                · <span class="inline-flex items-center gap-1"><span class="h-1.5 w-3 rounded-full" style="background: {{ $d->lineColor() }}"></span>{{ $d->lineName() }}</span>
                            </div>
                        </div>
                        <button type="button" wire:click="toggleMark({{ $d->id }}, 'going')" @class(['rounded-full border px-2.5 py-0.5 text-[12.5px]', 'border-flag font-semibold text-flag' => in_array('going', $marks), 'border-rule text-mute' => ! in_array('going', $marks)])>Going {{ $d->going_count }}</button>
                        <button type="button" wire:click="toggleMark({{ $d->id }}, 'interested')" @class(['rounded-full border px-2.5 py-0.5 text-[12.5px]', 'border-ink font-semibold' => in_array('interested', $marks), 'border-rule text-mute' => ! in_array('interested', $marks)])>Interested</button>
                        <a href="{{ route('happenings.ics', $d) }}" class="text-[12.5px] text-mute underline">.ics</a>
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <x-empty title="Nothing on the calendar yet" art="platform">Post a Happening with a start time and it shows up here.</x-empty>
    @endforelse

    @auth
        <section class="rounded-xl border border-rule bg-card p-4 text-sm">
            <h2 class="font-semibold">Your calendar feed</h2>
            <p class="text-mute">Subscribe in Apple Calendar, Google Calendar or Outlook to see the happenings you host, are going to or are interested in. Keep the link private.</p>
            @if ($feedUrl)
                <input readonly value="{{ $feedUrl }}" aria-label="Calendar feed link" class="mt-2 w-full rounded-md border border-rule bg-paper px-2 py-1 font-mono text-[12px]" x-on:focus="$el.select()">
                <button type="button" wire:click="resetFeed" wire:confirm="Make a new link? The old one stops working." class="mt-2 text-mute underline">Make a new link</button>
            @else
                <button type="button" wire:click="$set('showFeed', true)" class="mt-2 rounded-lg border border-rule px-3 py-1.5 font-semibold">Show my calendar link</button>
            @endif
        </section>
    @endauth
</div>
