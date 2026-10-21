<x-layouts::stoop :title="$game->matchup()">
    <div class="space-y-4">
        @include('partials.games-nav')
        <section class="rounded-xl border border-rule bg-card p-5" @if ($team) style="border-top: 6px solid {{ $team['colors'][0] }}" @endif>
            <a href="{{ $team ? route('sports.team', $game->team_key) : route('sports') }}" wire:navigate class="text-sm text-mute hover:text-ink">← {{ $team['full'] ?? 'Sports' }}</a>
            <h1 class="mt-1 font-display text-3xl font-bold">{{ $game->matchup() }}</h1>
            <p class="text-mute">{{ $game->when() }}{{ $game->venue ? ' · '.$game->venue : '' }}{{ $game->league ? ' · '.$game->league : '' }}</p>
            @if ($game->score())
                <p class="mt-2 font-display text-2xl font-bold">
                    @if ($game->isLive())<span class="mr-1 inline-block size-2.5 animate-pulse rounded-full bg-flag align-middle motion-reduce:animate-none"></span>@endif
                    {{ $game->score() }}
                    <span class="text-base font-normal text-mute">{{ $game->status === 'final' ? 'Final' : $game->status_detail }}</span>
                </p>
            @elseif ($game->status === 'off')
                <p class="mt-2 font-semibold">{{ $game->status_detail ?: 'Postponed' }}</p>
            @endif
            <div class="mt-3 flex flex-wrap gap-2 text-sm">
                @if ($game->dispatch_id)
                    <a href="{{ route('dispatches.show', $game->dispatch_id) }}" wire:navigate class="rounded-full bg-ink px-4 py-1.5 font-semibold text-paper">{{ $game->status === 'pre' ? 'Make your pick' : 'Game thread' }}</a>
                @endif
                @if ($team)
                    <a href="{{ $team['tickets'] }}" target="_blank" rel="noopener" class="rounded-full border border-rule px-4 py-1.5">Official tickets ↗</a>
                @endif
            </div>
        </section>

        @if ($game->stop)
            <section class="rounded-xl border border-rule bg-card p-5 text-sm">
                <h2 class="mb-1 text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">Getting there</h2>
                <p><b>{{ config('stoop.stations.'.$game->stop) }}</b> ({{ $game->stop }}) is the closest Metro stop to {{ $game->venue }}.
                    <a href="{{ route('home', ['stop' => $game->stop]) }}" wire:navigate class="underline">Posts from {{ config('stoop.stations.'.$game->stop) }}</a></p>
                @if (! $nearGameTime)
                    <p class="mt-1 text-mute">Live train times show here from three hours before the game.</p>
                @elseif ($trains === null)
                    <p class="mt-1 text-mute">Live train times aren’t available right now. Check wmata.com.</p>
                @elseif (! $trains)
                    <p class="mt-1 text-mute">No trains predicted right now.</p>
                @else
                    <ul class="mt-2 space-y-0.5">
                        @foreach ($trains as $t)
                            <li>{{ ucfirst((string) $t['line']) }} to {{ $t['destination'] }} · <b>{{ match ($t['minutes']) { 'ARR' => 'arriving', 'BRD' => 'boarding', default => is_numeric($t['minutes']) ? $t['minutes'].' min' : $t['minutes'] } }}</b></li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        @include('partials.watch-parties')
        @if ($game->status === 'pre')
            <p class="text-[13px] text-mute">Hosting? Post a Happening and pick this game under “Watch party”.</p>
        @endif
    </div>
</x-layouts::stoop>
