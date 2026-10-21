<div>
    <section class="rounded-xl border border-rule bg-card p-5">
        <div class="flex flex-wrap items-center gap-4">
            <div class="grid size-16 place-items-center rounded-full text-xl font-bold text-white" style="background: {{ $user->color() }}">{{ $user->initials() }}</div>
            <div class="min-w-0">
                <h1 class="font-display text-3xl font-bold leading-tight">{{ $user->name }}</h1>
                <div class="text-mute">{{ '@'.$user->username }} <span class="ml-1 rounded border border-rule px-1 text-[10px] font-bold">{{ $user->badge() }}</span>
                    @if ($followsYou) <span class="ml-1 rounded bg-chip px-1.5 text-[11px]">Follows you</span> @endif
                </div>
            </div>
            @if (auth()->id() !== $user->id)
                @auth
                    <a href="{{ route('messages', ['to' => $user->username]) }}" wire:navigate class="ml-auto rounded-full border border-rule px-4 py-1.5 text-sm">Message</a>
                @endauth
                <button type="button" wire:click="toggleFollow({{ $user->id }})" @class(['rounded-full border px-4 py-1.5 text-sm font-semibold', 'ml-auto' => ! auth()->check(), 'border-ink bg-ink text-paper' => $isFollowing, 'border-rule' => ! $isFollowing])>{{ $isFollowing ? 'Following' : 'Follow' }}</button>
            @else
                <a href="{{ route('profile.edit') }}" wire:navigate class="ml-auto rounded-full border border-rule px-4 py-1.5 text-sm">Edit profile</a>
            @endif
        </div>
        @if ($user->isLive())
            <a href="{{ $user->live_url }}" target="_blank" rel="noopener" class="mt-3 flex items-center gap-2 rounded-lg bg-flag/10 px-3 py-2 text-sm font-semibold text-flag">
                <span class="size-2 animate-pulse rounded-full bg-flag motion-reduce:animate-none"></span>
                On the scene{{ $user->live_label ? ': '.$user->live_label : '' }} · Watch
            </a>
        @endif

        <div class="mt-4 flex flex-wrap gap-5 text-sm text-mute">
            <span><b class="text-ink">{{ $user->followers_count }}</b> followers</span>
            <span><b class="text-ink">{{ $user->following_count }}</b> following</span>
            @if ($user->stops)
                <span>Stops: {{ collect($user->stops)->map(fn ($c) => $c.' · '.config("stoop.stations.$c"))->join(', ') }}</span>
            @endif
            @foreach (\App\Support\Sports::favorites($user) as $key)
                @include('partials.team-badge')
            @endforeach
        </div>
    </section>

    <section class="mt-3 rounded-xl border border-rule bg-card p-5">
        <div class="flex flex-wrap items-baseline gap-x-5 gap-y-1 text-sm text-mute">
            <span class="font-display text-xl font-bold text-ink">{{ $rank }}</span>
            <span><b class="text-ink tabular-nums">{{ number_format($xp) }}</b> XP</span>
            <span><b class="text-ink">{{ $streaks['current'] }}</b>-day streak</span>
            <span>Longest <b class="text-ink">{{ $streaks['longest'] }}</b></span>
            @if ($streaks['pass_used_this_week'])<span title="One missed day a week doesn’t break a streak.">Single-tracking pass used this week</span>@endif
        </div>
        <div class="mt-3 overflow-x-auto [&_svg]:max-w-none dark:[&_rect[fill='#e8ebe8']]:fill-[#262a2c]">{!! $graph !!}</div>
        @if ($badges)
            <ul class="mt-4 flex flex-wrap gap-3">
                @foreach ($badges as $key => $badge)
                    <li><x-art.badge :key="$key" :badge="$badge" /></li>
                @endforeach
            </ul>
        @endif
        <p class="mt-2 text-[12px] text-mute">Embed this graph anywhere: <code class="font-mono">{{ route('users.ridership', $user) }}</code></p>
    </section>

    <section class="mt-3 rounded-xl border border-rule bg-card p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-display text-xl font-bold">The system</h2>
            <span class="text-sm text-mute"><b class="tabular-nums text-ink">{{ count($visited) }}</b> of {{ count(config('metro.stations')) }} stations</span>
        </div>
        @if ($mayorships)
            <p class="mt-1 text-sm">Mayor of
                @foreach ($mayorships as $code)
                    <a href="{{ route('stops.show', $code) }}" wire:navigate class="mr-1.5 inline-block"><x-station :code="$code" /></a>
                @endforeach
            </p>
        @endif
        <x-strip-map :visited="$visited" class="mt-3" />
        <p class="mt-2 text-[12px] text-mute">Post from a station to fill it in. Finish a line for its badge.</p>
    </section>

    @if (auth()->id() === $user->id)
        <details class="mt-3 rounded-xl border border-rule bg-card p-4 text-sm" @if ($errors->hasAny(['liveUrl', 'liveLabel', 'liveHours'])) open @endif>
            <summary class="cursor-pointer font-semibold">{{ $user->isLive() ? 'You’re on the scene' : 'Go on the scene' }}</summary>
            @if ($user->isLive())
                <p class="mt-2 text-mute">Until {{ $user->live_until->format('g:ia') }}. <button type="button" wire:click="endLive" class="underline">End it now</button></p>
            @else
                <form wire:submit="goLive" class="mt-2 grid gap-2 sm:grid-cols-[1fr_1fr_auto_auto]">
                    <input wire:model="liveUrl" placeholder="YouTube, Twitch or Instagram link" aria-label="Stream link" class="rounded-md border border-rule bg-paper px-2.5 py-1.5">
                    <input wire:model="liveLabel" maxlength="80" placeholder="Where? (optional)" aria-label="Where" class="rounded-md border border-rule bg-paper px-2.5 py-1.5">
                    <select wire:model="liveHours" aria-label="For how long" class="rounded-md border border-rule bg-paper px-2 py-1.5">
                        @foreach ([1, 2, 3, 4, 6, 8, 12] as $h)<option value="{{ $h }}">{{ $h }} {{ str('hour')->plural($h) }}</option>@endforeach
                    </select>
                    <button type="submit" class="rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Go</button>
                </form>
                @error('liveUrl') <p class="mt-1 text-flag">{{ $message }}</p> @enderror
            @endif
        </details>
    @endif

    <div class="mt-4 mb-3 flex gap-0.5 border-b border-rule">
        @foreach (['dispatches' => 'Dispatches', 'replies' => 'Replies'] as $key => $label)
            <button type="button" wire:click="$set('show', '{{ $key }}')" @class(['border-b-2 px-3.5 py-2 text-sm font-medium', 'border-flag' => $show === $key, 'border-transparent text-mute' => $show !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    <div class="space-y-2.5">
        @forelse ($dispatches as $dispatch)
            <x-dispatch-card :dispatch="$dispatch" :translated="$translations[$dispatch->id] ?? null" :state="$state" :pinned="$dispatch->id === $pinnedId" />
        @empty
            <x-empty title="Nothing filed yet" art="stoop">{{ auth()->id() === $user->id ? 'Your first dispatch is waiting.' : $user->name.' hasn’t posted yet.' }}</x-empty>
        @endforelse
    </div>

    @include('partials.report-dialog')
    @include('partials.undo-bar')
</div>
