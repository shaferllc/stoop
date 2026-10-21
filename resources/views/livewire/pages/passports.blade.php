<div>
    <h1 class="font-display text-3xl font-bold">Passports</h1>
    <p class="text-mute">Every list from the magazine is a passport. Tap “Been here” at {{ \App\Support\Passports::STAMP_AT }} of its places to earn the stamp. Stamp holders get a shout-out in the magazine.</p>

    <div class="mt-4 space-y-4">
        @forelse ($lists as $name => $places)
            @php($p = $passports->firstWhere('list', $name))
            <section class="rounded-xl border border-rule bg-card p-5">
                <div class="flex flex-wrap items-center gap-3">
                    <h2 class="font-display text-xl font-bold">{{ $name }}</h2>
                    @if ($p && $p['stamped_at'])
                        <span class="rotate-[-6deg] rounded-md border-2 border-flag px-2 py-0.5 font-mono text-[12px] font-bold uppercase text-flag">Stamped {{ \Illuminate\Support\Carbon::parse($p['stamped_at'])->format('M j') }}</span>
                    @endif
                    <span class="ml-auto text-sm text-mute tabular-nums">{{ $p['been'] ?? 0 }} / {{ $p['needed'] ?? min(\App\Support\Passports::STAMP_AT, $places->count()) }}</span>
                </div>
                @php($pct = $p ? min(100, round($p['been'] / max(1, $p['needed']) * 100)) : 0)
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-chip" role="progressbar" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100"><div class="h-full bg-flag" style="width: {{ $pct }}%"></div></div>
                <ul class="mt-3 grid gap-1.5 text-sm sm:grid-cols-2">
                    @foreach ($places as $place)
                        @php($been = in_array($place->id, $beenIds))
                        <li class="flex items-center gap-2">
                            <span @class(['grid size-5 shrink-0 place-items-center rounded-full border text-[11px] font-bold', 'border-flag bg-flag text-white' => $been, 'border-rule text-mute' => ! $been])>{{ $been ? '✓' : ($place->list_rank ?: '') }}</span>
                            <a href="{{ route('places.show', $place) }}" wire:navigate @class(['hover:underline', 'text-mute' => ! $been])>{{ $place->name }}</a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <x-empty title="No lists yet" art="stoop">Staff import the magazine’s lists from Admin → Places.</x-empty>
        @endforelse
    </div>

    @auth @include('partials.hunt-stamps') @endauth
</div>
