{{-- One DC game in a list: matchup, time or score, and links to its page and pick thread. --}}
<li class="flex flex-wrap items-center gap-x-2 gap-y-1 px-4 py-2.5 text-sm">
    @include('partials.team-badge', ['key' => $g->team_key])
    <a href="{{ route('sports.game', $g) }}" wire:navigate class="font-semibold hover:underline">{{ $g->matchup() }}</a>
    @if ($g->isLive())
        <span class="inline-flex items-center gap-1 rounded bg-flag/10 px-1.5 text-[12px] font-semibold text-flag"><span class="size-1.5 animate-pulse rounded-full bg-flag motion-reduce:animate-none"></span>{{ $g->score() }}{{ $g->status_detail ? ' · '.$g->status_detail : '' }}</span>
    @elseif ($g->status === 'final')
        <span class="rounded bg-chip px-1.5 text-[12px]">Final: {{ $g->score() }}</span>
    @elseif ($g->status === 'off')
        <span class="rounded bg-chip px-1.5 text-[12px]">{{ $g->status_detail ?: 'Postponed' }}</span>
    @else
        <span class="text-mute">{{ $g->when() }}</span>
    @endif
    @if ($g->dispatch_id)
        <a href="{{ route('dispatches.show', $g->dispatch_id) }}" wire:navigate class="ml-auto underline">{{ $g->status === 'pre' ? 'Make your pick' : 'Thread' }}</a>
    @endif
</li>
