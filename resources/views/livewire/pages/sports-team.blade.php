<div class="space-y-4" @if ($live->isNotEmpty()) wire:poll.30s.visible @endif>
    @include('partials.games-nav')
    <section class="rounded-xl border border-rule bg-card p-5" style="border-top: 6px solid {{ $config['colors'][0] }}">
        <a href="{{ route('sports') }}" wire:navigate class="text-sm text-mute hover:text-ink">← Sports</a>
        <div class="mt-1 flex flex-wrap items-center gap-3">
            <div>
                <h1 class="font-display text-3xl font-bold">{{ $config['full'] }}</h1>
                <p class="text-sm text-mute">{{ $config['league'] }} · {{ $config['venue'] }} · <a href="{{ route('home', ['stop' => $config['stop']]) }}" wire:navigate class="underline">{{ $config['stop'] }} {{ config('stoop.stations.'.$config['stop']) }}</a></p>
                @if ($standing)
                    <p class="text-sm">{{ $standing['record'] }}{{ $standing['seed'] ? ' · #'.$standing['seed'] : '' }} in the {{ $standing['group'] }}</p>
                @endif
            </div>
            <button type="button" wire:click="toggleFan" @class(['ml-auto rounded-full border px-4 py-1.5 text-sm font-semibold', 'border-ink bg-ink text-paper' => $isFan, 'border-rule' => ! $isFan]) aria-pressed="{{ $isFan ? 'true' : 'false' }}">{{ $isFan ? 'You’re a fan' : 'I’m a fan' }}</button>
        </div>
        <p class="mt-2 text-sm text-mute"><b class="text-ink">{{ number_format($fanCount) }}</b> {{ str('fan')->plural($fanCount) }} on Stoop · <a href="{{ $config['tickets'] }}" target="_blank" rel="noopener" class="underline">Official tickets</a></p>
    </section>

    @foreach (['Live now' => $live, 'Coming up' => $upcoming, 'Results' => $results] as $heading => $games)
        @continue($games->isEmpty())
        <section>
            <h2 class="mb-2 text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">{{ $heading }}</h2>
            <ul class="divide-y divide-rule rounded-xl border border-rule bg-card">
                @foreach ($games as $g)
                    @include('partials.sports-game')
                @endforeach
            </ul>
        </section>
    @endforeach

    @if ($threads->isNotEmpty())
        <section>
            <h2 class="mb-2 text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">Game threads</h2>
            <ul class="divide-y divide-rule rounded-xl border border-rule bg-card text-sm">
                @foreach ($threads as $g)
                    <li class="px-4 py-2.5"><a href="{{ route('dispatches.show', $g->dispatch_id) }}" wire:navigate class="hover:underline">{{ $g->matchup() }}</a> <span class="text-mute">· {{ $g->when() }}</span></li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($fans->isNotEmpty())
        <section>
            <h2 class="mb-2 text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">Fans</h2>
            <ul class="flex flex-wrap gap-2">
                @foreach ($fans as $fan)
                    <li><a href="{{ route('users.show', $fan) }}" wire:navigate class="flex items-center gap-1.5 rounded-full border border-rule bg-card py-0.5 pl-0.5 pr-2.5 text-sm hover:border-ink">
                        <span class="grid size-6 place-items-center rounded-full text-[10px] font-bold text-white" style="background: {{ $fan->color() }}">{{ $fan->initials() }}</span>{{ $fan->name }}
                    </a></li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
