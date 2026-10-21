<div class="space-y-3">
    <section class="rounded-xl border border-rule bg-card p-6">
        <div class="text-[11.5px] font-semibold uppercase tracking-wide text-flag">Contest</div>
        <h1 class="font-display text-3xl font-bold">{{ $contest->title }}</h1>
        <p class="mt-1 text-lg">{{ $contest->question }}</p>
        @if ($contest->prize)<p class="mt-1 text-sm text-mute">Prize: {{ $contest->prize }}</p>@endif
        <p class="mt-1 text-sm text-mute">{{ $contest->entries_count }} {{ str('guess')->plural($contest->entries_count) }} so far ·
            {{ $contest->isOpen() ? 'Guessing closes '.$contest->closes_at->timezone(config('stoop.timezone'))->format('M j, g:ia') : 'Guessing is closed' }}</p>

        @if ($contest->isOpen())
            @auth
                <form wire:submit="enter" class="mt-3 flex flex-wrap items-end gap-2">
                    <label class="text-sm">Your guess<input type="date" wire:model="guess" class="mt-0.5 block rounded-md border border-rule bg-paper px-2.5 py-1.5"></label>
                    <button class="rounded-lg bg-flag px-4 py-2 text-sm font-semibold text-white">{{ $guess ? 'Update guess' : 'Enter' }}</button>
                </form>
                @error('guess') <p class="mt-1 text-sm text-flag">{{ $message }}</p> @enderror
            @else
                <p class="mt-3 text-sm"><a href="{{ route('login') }}" class="underline">Log in</a> to guess.</p>
            @endauth
        @endif
    </section>

    @if ($contest->answer)
        <section class="rounded-xl border border-rule bg-card p-5">
            <h2 class="font-display text-xl font-bold">The answer: {{ $contest->answer->format('F j, Y') }}</h2>
            @if ($mine !== false)<p class="mt-1 text-sm">You finished <b>#{{ $mine + 1 }}</b> of {{ $standings->count() }}.</p>@endif
            <ol class="mt-3 space-y-1 text-sm">
                @foreach ($standings->take(max(10, $contest->winners)) as $i => $entry)
                    <li @class(['flex gap-3', 'font-semibold' => $i < $contest->winners])>
                        <span class="w-6 tabular-nums">{{ $i < $contest->winners ? '🌸' : $i + 1 }}</span>
                        <a href="{{ route('users.show', $entry->user) }}" wire:navigate>{{ '@'.$entry->user->username }}</a>
                        <span class="text-mute">guessed {{ $entry->guess->format('M j') }}{{ $entry->guess->equalTo($contest->answer) ? ', exactly right' : ', off by '.abs($entry->guess->diffInDays($contest->answer)).' '.str('day')->plural(abs($entry->guess->diffInDays($contest->answer))) }}</span>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif
</div>
