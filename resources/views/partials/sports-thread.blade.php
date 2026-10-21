{{-- Under a game-day thread: the score, the game page and watch parties. --}}
@if (! empty($dispatch->details['game']) && $game = \App\Models\Game::find($dispatch->details['game']))
    <div class="rounded-xl border border-rule bg-card px-4 py-2.5 text-sm">
        @include('partials.team-badge', ['key' => $game->team_key])
        <b>{{ $game->score() ?? $game->matchup() }}</b>
        <span class="text-mute">· {{ $game->status === 'final' ? 'Final' : ($game->isLive() ? $game->status_detail : $game->when()) }}</span>
        <a href="{{ route('sports.game', $game) }}" wire:navigate class="ml-1 underline">Trains, tickets and watch parties</a>
    </div>
    @include('partials.watch-parties', ['parties' => $game->watchParties()->where('dispatches.status', 'approved')->with('user')->get()])
@endif
