@php($tz = config('stoop.timezone'))
@php($checked = array_flip($progress['place_ids']))
<div class="space-y-3">
    <div>
        <a href="{{ route('challenges') }}" wire:navigate class="text-sm text-mute hover:underline">← Challenges</a>
        <h1 class="font-display text-3xl font-bold">{{ $challenge->title }}</h1>
        <x-presented-by :target="'challenge:'.$challenge->id" />
        <p class="text-sm text-mute">{{ $challenge->list_name }} · {{ $challenge->starts_at->timezone($tz)->format('M j') }} – {{ $challenge->ends_at->timezone($tz)->format('M j, g:ia') }} · Badge: {{ $challenge->badge_name }}</p>
        @if ($challenge->prize)<p class="mt-1 max-w-prose"><b>Prize:</b> {{ $challenge->prize }}</p>@endif
    </div>

    @if (! $challenge->isRunning())
        <p class="rounded-lg bg-chip px-3 py-2 text-sm text-mute">{{ $challenge->starts_at->isFuture() ? 'This challenge starts '.$challenge->starts_at->timezone($tz)->format('l, M j').'. Check-ins count from then.' : 'This challenge is over. Thanks for playing.' }}</p>
    @endif

    <section class="rounded-xl border border-rule bg-card p-4">
        @auth
            <div class="flex items-baseline justify-between text-sm">
                <h2 class="font-semibold">Your progress</h2>
                <span class="tabular-nums">{{ min($progress['count'], $progress['goal']) }} of {{ $progress['goal'] }}</span>
            </div>
            <div class="mt-1.5 h-2.5 overflow-hidden rounded-full bg-chip" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $progress['goal'] }}" aria-valuenow="{{ min($progress['count'], $progress['goal']) }}" aria-label="Your progress">
                <div class="h-full bg-flag" style="width: {{ min(100, round($progress['count'] / max(1, $progress['goal']) * 100)) }}%"></div>
            </div>
            @if ($progress['finished_at'])
                <p class="mt-1.5 text-sm font-semibold text-flag">Finished {{ \Illuminate\Support\Carbon::parse($progress['finished_at'])->timezone($tz)->format('M j') }}. You earned “{{ $challenge->badge_name }}”.</p>
            @else
                <p class="mt-1.5 text-sm text-mute">Tap Been here on a place’s post (or post a Been here) to check it off.</p>
            @endif
        @else
            <a href="{{ route('login') }}" class="text-sm underline">Log in to play</a>
        @endauth
    </section>

    <section class="rounded-xl border border-rule bg-card p-4">
        <h2 class="text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">On the list</h2>
        <ul class="mt-2 grid gap-1.5 text-sm sm:grid-cols-2">
            @foreach ($places as $place)
                @php($done = isset($checked[$place->id]))
                <li class="flex items-center gap-2">
                    <span @class(['grid size-5 shrink-0 place-items-center rounded-full text-[11px] font-bold', 'bg-flag text-white' => $done, 'bg-chip text-mute' => ! $done]) aria-label="{{ $done ? 'Been here' : 'Not yet' }}">{{ $done ? '✓' : '' }}</span>
                    <a href="{{ route('places.show', $place) }}" wire:navigate @class(['hover:underline', 'font-semibold' => $done])>{{ $place->name }}</a>
                    @if ($place->neighborhood)<span class="text-mute">· {{ $place->neighborhood }}</span>@endif
                </li>
            @endforeach
        </ul>
    </section>

    <section class="rounded-xl border border-rule bg-card p-4">
        <h2 class="text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">Leaderboard</h2>
        <ol class="mt-2 space-y-1.5 text-sm">
            @forelse ($leaders as $row)
                <li class="flex items-center gap-3">
                    <span class="w-5 text-mute tabular-nums">{{ $loop->iteration }}</span>
                    <a href="{{ route('users.show', $row->username) }}" wire:navigate class="flex-1 font-semibold">{{ '@'.$row->username }}</a>
                    <span class="tabular-nums">{{ $row->count }} / {{ $challenge->goal }}</span>
                </li>
            @empty
                <li class="text-mute">Nobody’s checked in yet.</li>
            @endforelse
        </ol>
    </section>
</div>
