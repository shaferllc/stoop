<div x-data="{
        recent: (() => { try { return JSON.parse(localStorage.getItem('stoop.recent') || '[]') } catch { return [] } })(),
        remember(q) { q = q.trim(); if (q.length < 2) return; this.recent = [q, ...this.recent.filter(r => r !== q)].slice(0, 3); try { localStorage.setItem('stoop.recent', JSON.stringify(this.recent)) } catch {} },
    }" x-init="remember(@js($q))">
    <h1 class="mb-3 font-display text-3xl font-bold">Search</h1>

    <label for="search-q" class="sr-only">Search</label>
    <input id="search-q" type="search" wire:model.live.debounce.400ms="q" x-on:change="remember($event.target.value)" autofocus placeholder="Search dispatches, people or stations"
        class="w-full rounded-xl border border-rule bg-card px-4 py-2.5 outline-none focus:border-ink">
    @error('q') <p class="mt-1 text-sm text-flag">{{ $message }}</p> @enderror

    <div class="mt-2 flex flex-wrap gap-1.5 text-[13px]" x-show="recent.length" x-cloak>
        <span class="text-mute">Recent:</span>
        <template x-for="r in recent" :key="r"><button type="button" x-on:click="$wire.set('q', r)" class="rounded-full bg-chip px-2.5" x-text="r"></button></template>
    </div>

    <div class="mt-4 mb-3 flex gap-0.5 border-b border-rule">
        @foreach (['dispatches' => 'Dispatches', 'people' => 'People', 'stations' => 'Stations'] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['border-b-2 px-3.5 py-2 text-sm font-medium', 'border-flag' => $tab === $key, 'border-transparent text-mute' => $tab !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    @if (! $valid)
        <p class="text-mute">Type at least 2 characters.</p>
    @elseif ($tab === 'dispatches')
        @if ($magazine->isNotEmpty())
            <section class="mb-4 rounded-xl border border-rule bg-card p-4" aria-labelledby="from-the-magazine">
                <h2 id="from-the-magazine" class="text-[11px] font-semibold uppercase tracking-wide text-mute">From the magazine</h2>
                <ul class="mt-1.5 space-y-1.5">
                    @foreach ($magazine as $story)
                        <li wire:key="mag-{{ $story->id }}"><a href="{{ $story->url }}" target="_blank" rel="noopener" class="font-semibold hover:underline">{{ $story->title }}</a> <span class="text-[13px] text-mute">{{ $story->section }}{{ $story->published_on ? ' · '.\Illuminate\Support\Carbon::parse($story->published_on)->format('M Y') : '' }}</span></li>
                    @endforeach
                </ul>
            </section>
        @endif
        <div class="space-y-2.5">
            @forelse ($dispatches as $dispatch)
                <x-dispatch-card :dispatch="$dispatch" :translated="$translations[$dispatch->id] ?? null" :state="$state" />
            @empty
                <p class="text-mute">No dispatches match “{{ $q }}”.</p>
            @endforelse
        </div>
    @elseif ($tab === 'people')
        <ul class="divide-y divide-rule rounded-xl border border-rule bg-card">
            @forelse ($people as $person)
                <li class="flex items-center gap-3 px-4 py-2.5">
                    <span class="grid size-9 place-items-center rounded-full text-xs font-bold text-white" style="background: {{ $person->color() }}">{{ $person->initials() }}</span>
                    <a href="{{ route('users.show', $person) }}" wire:navigate class="min-w-0 flex-1"><b>{{ $person->name }}</b> <span class="text-mute">{{ '@'.$person->username }} · {{ $person->badge() }}</span></a>
                    <span class="text-[13px] text-mute">{{ $person->followers_count }} followers</span>
                </li>
            @empty
                <li class="p-6 text-center text-mute">No one matches “{{ $q }}”.</li>
            @endforelse
        </ul>
    @else
        <ul class="divide-y divide-rule rounded-xl border border-rule bg-card">
            @forelse ($stations as $code => $name)
                <li><a href="{{ route('stops.show', $code) }}" wire:navigate class="block px-4 py-2.5 hover:bg-chip/50"><span class="font-mono text-mute">{{ $code }}</span> · {{ $name }}</a></li>
            @empty
                <li class="p-6 text-center text-mute">No station matches “{{ $q }}”.</li>
            @endforelse
        </ul>
    @endif

    @include('partials.report-dialog')
    @include('partials.undo-bar')
</div>
