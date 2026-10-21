@php($tz = config('stoop.timezone'))
<div class="space-y-3">
    <div>
        <h1 class="font-display text-3xl font-bold">Challenges</h1>
        <p class="text-mute">Work your way through the magazine’s lists. Tap Been here at places on the list while a challenge runs, hit the goal, and earn its badge.</p>
    </div>

    @foreach (['Running now' => $running, 'Coming up' => $upcoming, 'Finished' => $past] as $heading => $challenges)
        @continue($challenges->isEmpty())
        <section>
            <h2 class="mb-1.5 text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">{{ $heading }}</h2>
            <ul class="divide-y divide-rule rounded-xl border border-rule bg-card">
                @foreach ($challenges as $c)
                    <li class="px-4 py-3">
                        <a href="{{ route('challenges.show', $c) }}" wire:navigate class="font-display text-lg font-bold hover:underline">{{ $c->title }}</a>
                        <div class="text-sm text-mute">{{ $c->list_name }} · {{ $c->starts_at->timezone($tz)->format('M j') }} – {{ $c->ends_at->timezone($tz)->format('M j') }}</div>
                        @if (auth()->check() && isset($progress[$c->id]))
                            @php($p = $progress[$c->id])
                            <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-chip" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $p['goal'] }}" aria-valuenow="{{ min($p['count'], $p['goal']) }}" aria-label="Progress">
                                <div class="h-full bg-flag" style="width: {{ min(100, round($p['count'] / max(1, $p['goal']) * 100)) }}%"></div>
                            </div>
                            <p class="mt-0.5 text-[12.5px] text-mute">{{ min($p['count'], $p['goal']) }} of {{ $p['goal'] }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach

    @if ($running->isEmpty() && $upcoming->isEmpty() && $past->isEmpty())
        <x-empty title="No challenges yet" art="platform">Editors set up challenges on the magazine’s lists. Check back soon.</x-empty>
    @endif
</div>
