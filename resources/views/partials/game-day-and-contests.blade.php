{{-- Game-day picks and contests, shown in the Play hub. --}}
@if ($games->isNotEmpty())
    <section>
        <h2 class="mb-2 text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">Game day</h2>
        <ul class="divide-y divide-rule rounded-xl border border-rule bg-card text-sm">
            @foreach ($games as $g)
                <li class="flex flex-wrap items-center gap-2 px-4 py-2.5">
                    <b>{{ $g->team }}</b> {{ $g->home ? 'vs.' : 'at' }} {{ $g->opponent }}
                    <span class="text-mute">· {{ $g->starts_at->timezone(config('stoop.timezone'))->format('D M j, g:ia') }}</span>
            @if ($g->winner)<span class="rounded bg-chip px-1.5 text-[12px]">{{ match ($g->winner) { 'team' => $g->team.' won', 'opponent' => $g->opponent.' won', default => 'Draw' } }}</span>@endif
                    @if ($g->dispatch_id)<a href="{{ route('dispatches.show', $g->dispatch_id) }}" wire:navigate class="ml-auto underline">{{ $g->starts_at->isFuture() ? 'Make your pick' : 'Thread' }}</a>@endif
                </li>
            @endforeach
        </ul>
        <p class="mt-1 text-[12.5px] text-mute">Right picks are worth 10 XP each. Five right picks earn the Called It badge. <a href="{{ route('sports') }}" wire:navigate class="underline">Scores, schedules and pick’em leaders</a></p>
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

