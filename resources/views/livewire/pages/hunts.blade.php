@php($tz = config('stoop.timezone'))
<div class="space-y-3">
    @include('partials.games-nav')
    <div>
        <h1 class="font-display text-3xl font-bold">Scavenger hunts</h1>
        <p class="text-mute">Seasonal clues that lead to stations and places around the DMV. Crack a clue, post a Been here or a dispatch from the spot, and check in for a passport stamp.</p>
    </div>

    @foreach (['Running now' => $running, 'Coming up' => $upcoming, 'Finished' => $past] as $heading => $hunts)
        @continue($hunts->isEmpty())
        <section>
            <h2 class="mb-1.5 text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">{{ $heading }}</h2>
            <ul class="divide-y divide-rule rounded-xl border border-rule bg-card">
                @foreach ($hunts as $hunt)
                    <li class="px-4 py-3">
                        <a href="{{ route('hunts.show', $hunt) }}" wire:navigate class="font-display text-lg font-bold hover:underline">{{ $hunt->name }}</a>
                        <div class="text-sm text-mute">{{ $hunt->steps_count }} {{ str('clue')->plural($hunt->steps_count) }} · {{ $hunt->starts_at->timezone($tz)->format('M j') }} – {{ $hunt->ends_at->timezone($tz)->format('M j') }}</div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach

    @if ($running->isEmpty() && $upcoming->isEmpty() && $past->isEmpty())
        <x-empty title="No hunts yet" art="platform">Editors set up a hunt each season. Check back soon.</x-empty>
    @endif
</div>
