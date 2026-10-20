<div class="rounded-xl border border-rule bg-card p-3.5 text-sm shadow-lg">
    <div class="flex items-center gap-2.5">
        <span class="grid size-10 place-items-center rounded-full font-bold text-white" style="background: {{ $user->color() }}">{{ $user->initials() }}</span>
        <div class="min-w-0">
            <div class="truncate font-semibold">{{ $user->name }}</div>
            <div class="text-mute">{{ '@'.$user->username }} · {{ $user->badge() }}</div>
        </div>
    </div>
    <div class="mt-2 flex gap-4 text-mute">
        <span><b class="text-ink">{{ $user->followers_count }}</b> followers</span>
        <span><b class="text-ink">{{ $user->following_count }}</b> following</span>
    </div>
    @if ($user->stops)
        <div class="mt-1.5 text-[12.5px] text-mute">{{ collect($user->stops)->map(fn ($c) => config("stoop.stations.$c"))->join(' · ') }}</div>
    @endif
</div>
