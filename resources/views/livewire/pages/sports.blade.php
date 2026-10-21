<div class="space-y-4" @if ($live) wire:poll.30s.visible @endif>
    @include('partials.games-nav')
    <div>
        <h1 class="font-display text-3xl font-bold">Sports</h1>
        <p class="text-mute">Every DC game, live scores, and a pick thread from @gameday on game day. Right picks are worth 10 XP.</p>
    </div>

    <section>
        <h2 class="mb-2 text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">Today</h2>
        @if ($today->isEmpty())
            <p class="rounded-xl border border-rule bg-card px-4 py-3 text-sm text-mute">No DC games today.</p>
        @else
            <ul class="divide-y divide-rule rounded-xl border border-rule bg-card">
                @foreach ($today as $g)
                    @include('partials.sports-game')
                @endforeach
            </ul>
        @endif
    </section>

    @if ($week->isNotEmpty())
        <section>
            <h2 class="mb-2 text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">This week</h2>
            <ul class="divide-y divide-rule rounded-xl border border-rule bg-card">
                @foreach ($week as $g)
                    @include('partials.sports-game')
                @endforeach
            </ul>
        </section>
    @endif

    <section>
        <h2 class="mb-2 text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">Teams</h2>
        <ul class="grid gap-2 sm:grid-cols-2">
            @foreach ($teams as $key => $t)
                @php($standing = \App\Support\Sports::standings($key))
                <li><a href="{{ route('sports.team', $key) }}" wire:navigate class="flex h-full flex-col rounded-xl border border-rule bg-card p-4 hover:border-ink" style="border-left: 4px solid {{ $t['colors'][0] }}">
                    <span class="text-[11px] font-semibold uppercase tracking-wide text-mute">{{ $t['league'] }}</span>
                    <span class="font-display text-lg font-bold">{{ $t['full'] }}</span>
                    @if ($standing)
                        <span class="text-[13px]">{{ $standing['record'] }}{{ $standing['seed'] ? ' · #'.$standing['seed'] : '' }} in the {{ $standing['group'] }}</span>
                    @endif
                    <span class="text-[13px] text-mute">Fan of: {{ number_format($fans[$key] ?? 0) }}</span>
                </a></li>
            @endforeach
        </ul>
    </section>

    @if ($boards->isNotEmpty())
        <section>
            <h2 class="mb-2 text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">Pick’em leaders this season</h2>
            <div class="grid gap-2 sm:grid-cols-2">
                @foreach ($boards as $league => $rows)
                    <div class="rounded-xl border border-rule bg-card p-4">
                        <h3 class="font-display font-bold">{{ $league }}</h3>
                        <ol class="mt-1 space-y-0.5 text-sm">
                            @foreach ($rows as $i => $row)
                                <li class="flex gap-2"><span class="w-4 text-mute">{{ $i + 1 }}</span><a href="{{ route('users.show', $row->username) }}" wire:navigate class="hover:underline">{{ $row->name }}</a><span class="ml-auto tabular-nums">{{ $row->right }} right</span></li>
                            @endforeach
                        </ol>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <p class="text-[12.5px] text-mute">Schedules and scores from ESPN. Five right picks earn the Called It badge.</p>
</div>
