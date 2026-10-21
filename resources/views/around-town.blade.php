<x-layouts::stoop title="Around town">
    <div class="space-y-4">
        <div>
            <h1 class="font-display text-3xl font-bold">Around town</h1>
            <p class="text-mute">What’s happening in the city, and the games and contests going on Stoop.</p>
        </div>

        @if ($snowDay)
            <a href="{{ route('snow') }}" class="block rounded-xl border-2 border-[#009cde] bg-[#009cde]/10 p-4">
                <div class="font-display text-xl font-bold">❄ It’s a Snow Day</div>
                <p class="text-sm">Report how much snow you have and whether your street’s plowed. See the snow map.</p>
            </a>
        @endif

        @if ($events->isNotEmpty())
            <section>
                <h2 class="mb-2 text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">Events and meetups</h2>
                <ul class="grid gap-2 sm:grid-cols-2">
                    @foreach ($events as $e)
                        <li><a href="{{ route('events.show', $e) }}" wire:navigate class="block rounded-xl border border-rule bg-card p-4 hover:border-ink">
                            <div class="text-[11px] font-semibold uppercase tracking-wide {{ $e->isHappening() ? 'text-flag' : 'text-mute' }}">{{ $e->kind === 'meetup' ? 'Stoop Sits' : ($e->isHappening() ? 'Happening now' : 'Coming up') }}</div>
                            <div class="font-display text-lg font-bold">{{ $e->name }}</div>
                            <div class="text-[13px] text-mute">{{ $e->starts_at->timezone(config('stoop.timezone'))->format('M j') }}{{ $e->ends_at->isSameDay($e->starts_at) ? '' : ' to '.$e->ends_at->timezone(config('stoop.timezone'))->format('M j') }}</div>
                        </a></li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($games->isNotEmpty())
            <section>
                <h2 class="mb-2 text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">Game day</h2>
                <ul class="divide-y divide-rule rounded-xl border border-rule bg-card text-sm">
                    @foreach ($games as $g)
                        <li class="flex flex-wrap items-center gap-2 px-4 py-2.5">
                            <b>{{ $g->team }}</b> {{ $g->home ? 'vs.' : 'at' }} {{ $g->opponent }}
                            <span class="text-mute">· {{ $g->starts_at->timezone(config('stoop.timezone'))->format('D M j, g:ia') }}</span>
                            @if ($g->winner)<span class="rounded bg-chip px-1.5 text-[12px]">{{ $g->winner === 'team' ? $g->team.' won' : $g->opponent.' won' }}</span>@endif
                            @if ($g->dispatch_id)<a href="{{ route('dispatches.show', $g->dispatch_id) }}" wire:navigate class="ml-auto underline">{{ $g->starts_at->isFuture() ? 'Make your pick' : 'Thread' }}</a>@endif
                        </li>
                    @endforeach
                </ul>
                <p class="mt-1 text-[12.5px] text-mute">Right picks are worth 10 XP each. Five right picks earn the Called It badge.</p>
            </section>
        @endif

        @if ($contests->isNotEmpty())
            <section>
                <h2 class="mb-2 text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">Contests</h2>
                <ul class="grid gap-2 sm:grid-cols-2">
                    @foreach ($contests as $c)
                        <li><a href="{{ route('contests.show', $c) }}" wire:navigate class="block rounded-xl border border-rule bg-card p-4 hover:border-ink">
                            <div class="font-display text-lg font-bold">{{ $c->title }}</div>
                            <div class="text-[13px] text-mute">{{ $c->isOpen() ? 'Open until '.$c->closes_at->timezone(config('stoop.timezone'))->format('M j') : ($c->answer ? 'Results are in' : 'Closed, waiting on the answer') }}</div>
                        </a></li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($events->isEmpty() && $games->isEmpty() && $contests->isEmpty() && ! $snowDay)
            <x-empty title="Quiet week in the District" art="stoop">Events, game days and contests show up here.</x-empty>
        @endif
    </div>
</x-layouts::stoop>
