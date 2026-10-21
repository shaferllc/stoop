{{-- Running list challenges, on the main feed. --}}
@foreach (\App\Models\Challenge::running()->orderBy('ends_at')->take(2)->get() as $challenge)
    @php($p = \App\Support\Challenges::progress($challenge, auth()->user()))
    <a href="{{ route('challenges.show', $challenge) }}" wire:navigate wire:key="challenge-card-{{ $challenge->id }}" class="block rounded-xl border border-rule bg-card p-4 hover:border-ink">
        <div class="text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">Challenge · ends {{ $challenge->ends_at->timezone(config('stoop.timezone'))->format('M j') }}</div>
        <div class="font-display text-lg font-bold">{{ $challenge->title }}</div>
        @auth
            <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-chip"><div class="h-full bg-flag" style="width: {{ min(100, round($p['count'] / max(1, $p['goal']) * 100)) }}%"></div></div>
            <div class="mt-0.5 text-[12.5px] text-mute">{{ $p['finished_at'] ? 'Finished. Badge earned: '.$challenge->badge_name : min($p['count'], $p['goal']).' of '.$p['goal'].' on '.$challenge->list_name }}</div>
        @else
            <div class="text-sm text-mute">{{ $challenge->goal }} places on {{ $challenge->list_name }}. Earn the “{{ $challenge->badge_name }}” badge.</div>
        @endauth
    </a>
@endforeach
