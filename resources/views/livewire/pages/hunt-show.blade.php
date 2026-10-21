@php($tz = config('stoop.timezone'))
<div class="space-y-3">
    @include('partials.games-nav')
    <div>
        <h1 class="font-display text-3xl font-bold">{{ $hunt->name }}</h1>
        <p class="text-sm text-mute">{{ $hunt->starts_at->timezone($tz)->format('M j') }} – {{ $hunt->ends_at->timezone($tz)->format('M j, g:ia') }} · {{ count($done) }} of {{ $hunt->steps->count() }} found</p>
        @if ($hunt->description)<p class="mt-1 max-w-prose whitespace-pre-line">{{ $hunt->description }}</p>@endif
    </div>

    @if (! $hunt->isRunning())
        <p class="rounded-lg bg-chip px-3 py-2 text-sm text-mute">{{ $hunt->starts_at->isFuture() ? 'This hunt starts '.$hunt->starts_at->timezone($tz)->format('l, M j').'. Clues show up then.' : 'This hunt is over. Thanks for playing.' }}</p>
    @endif

    @if (! $hunt->starts_at->isFuture())
        <ol class="space-y-2">
            @foreach ($hunt->steps as $step)
                @php($found = $done[$step->id] ?? null)
                <li wire:key="step-{{ $step->id }}" @class(['rounded-xl border bg-card p-4', 'border-flag' => $found, 'border-rule' => ! $found])>
                    <div class="flex items-start gap-3">
                        <span @class(['grid size-7 shrink-0 place-items-center rounded-full text-sm font-bold', 'bg-flag text-white' => $found, 'bg-chip text-mute' => ! $found])>{{ $found ? '✓' : $step->position }}</span>
                        <div class="min-w-0 flex-1">
                            <p>{{ $step->clue }}</p>
                            @if ($found)
                                <p class="text-[12.5px] font-semibold text-flag">Stamped {{ \Illuminate\Support\Carbon::parse($found)->timezone($tz)->format('M j') }}</p>
                            @elseif ($hunt->isRunning())
                                @auth
                                    <form wire:submit="checkIn({{ $step->id }})" class="mt-2 flex flex-wrap gap-2 text-sm">
                                        @if ($step->code_word)
                                            <input wire:model="codes.{{ $step->id }}" maxlength="40" placeholder="Code word" aria-label="Code word" class="rounded-md border border-rule bg-paper px-2 py-1">
                                        @endif
                                        <button class="rounded-lg border border-rule px-3 py-1 font-semibold">Check in</button>
                                    </form>
                                    @error("codes.$step->id") <p class="mt-1 text-sm text-flag">{{ $message }}</p> @enderror
                                @else
                                    <a href="{{ route('login') }}" class="mt-1 inline-block text-sm underline">Log in to play</a>
                                @endauth
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ol>
    @endif

    <section class="rounded-xl border border-rule bg-card p-4">
        <h2 class="text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">Leaderboard</h2>
        <ol class="mt-2 space-y-1.5 text-sm">
            @forelse ($leaders as $row)
                <li class="flex items-center gap-3">
                    <span class="w-5 text-mute tabular-nums">{{ $loop->iteration }}</span>
                    <a href="{{ route('users.show', $row->user) }}" wire:navigate class="flex-1 font-semibold">{{ '@'.$row->user->username }}</a>
                    <span class="tabular-nums">{{ $row->steps }} / {{ $hunt->steps->count() }}</span>
                </li>
            @empty
                <li class="text-mute">Nobody’s found a clue yet.</li>
            @endforelse
        </ol>
    </section>
</div>
