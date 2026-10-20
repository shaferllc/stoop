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
                <button type="button" wire:click="toggleFollow({{ $user->id }})" @class(['ml-auto rounded-full border px-4 py-1.5 text-sm font-semibold', 'border-ink bg-ink text-paper' => $isFollowing, 'border-rule' => ! $isFollowing])>{{ $isFollowing ? 'Following' : 'Follow' }}</button>
            @else
                <a href="{{ route('profile.edit') }}" wire:navigate class="ml-auto rounded-full border border-rule px-4 py-1.5 text-sm">Edit profile</a>
            @endif
        </div>
        <div class="mt-4 flex flex-wrap gap-5 text-sm text-mute">
            <span><b class="text-ink">{{ $user->followers_count }}</b> followers</span>
            <span><b class="text-ink">{{ $user->following_count }}</b> following</span>
            @if ($user->stops)
                <span>Stops: {{ collect($user->stops)->map(fn ($c) => $c.' · '.config("stoop.stations.$c"))->join(', ') }}</span>
            @endif
        </div>
    </section>

    <div class="mt-4 mb-3 flex gap-0.5 border-b border-rule">
        @foreach (['dispatches' => 'Dispatches', 'replies' => 'Replies'] as $key => $label)
            <button type="button" wire:click="$set('show', '{{ $key }}')" @class(['border-b-2 px-3.5 py-2 text-sm font-medium', 'border-flag' => $show === $key, 'border-transparent text-mute' => $show !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    <div class="space-y-2.5">
        @forelse ($dispatches as $dispatch)
            <x-dispatch-card :dispatch="$dispatch" :state="$state" :pinned="$dispatch->id === $pinnedId" />
        @empty
            <div class="rounded-xl border border-dashed border-rule p-8 text-center text-mute">Nothing filed yet.</div>
        @endforelse
    </div>

    @include('partials.report-dialog')
    @include('partials.undo-bar')
</div>
