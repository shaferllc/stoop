<div class="space-y-3">
    @include('partials.games-nav')
    <div>
        <h1 class="font-display text-3xl font-bold">Metro Bingo</h1>
        <p class="text-mute">A new card every Monday. Squares Stoop can see in your activity mark themselves; tap the others and pick one of this week’s posts as proof. Mark at least one square by hand and get five in a row for the Metro Bingo badge and {{ \App\Support\Bingo::XP }} XP.</p>
    </div>

    @if ($card['lines'])
        <div class="rounded-xl bg-flag px-4 py-2 text-center font-display text-xl font-bold text-white">Bingo! {{ $card['lines'] }} {{ str('line')->plural($card['lines']) }} this week.@unless ($card['played'])<div class="text-sm font-normal">Mark one square by hand to claim the badge.</div>@endunless</div>
    @endif

    <div class="grid grid-cols-5 gap-1.5" role="grid" aria-label="Bingo card for the week of {{ $card['week']->format('M j') }}">
        @foreach ($card['squares'] as $sq)
            <button type="button" role="gridcell" wire:key="sq-{{ $sq['key'] }}"
                @if (! $sq['marked'] && ! $sq['auto']) wire:click="startMarking('{{ $sq['key'] }}')" @else disabled @endif
                aria-pressed="{{ $sq['marked'] ? 'true' : 'false' }}"
                @class(['flex aspect-square flex-col items-center justify-center rounded-lg border p-1 text-center text-[11px] leading-tight sm:text-[13px]',
                    'border-flag bg-flag text-white' => $sq['marked'],
                    'border-rule bg-card' => ! $sq['marked'],
                    'cursor-pointer hover:border-ink' => ! $sq['marked'] && ! $sq['auto'],
                    'ring-2 ring-ink' => $marking === $sq['key']])>
                <span>{{ $sq['label'] }}</span>
                @unless ($sq['marked'])<span class="mt-0.5 text-[10px] text-mute">{{ $sq['auto'] ? 'Marks itself' : 'Tap with proof' }}</span>@endunless
            </button>
        @endforeach
    </div>

    @if ($marking)
        <form wire:submit="mark" class="space-y-2 rounded-xl border border-rule bg-card p-4 text-sm">
            <b>{{ \App\Support\Bingo::SQUARES[$marking][0] ?? '' }}</b>: pick a post from this week as proof.
            @if ($proofPosts->isEmpty())
                <p class="text-mute">You haven’t posted this week. Post about it first, then come back.</p>
            @else
                <select wire:model="proofId" aria-label="Proof post" class="block w-full rounded-md border border-rule bg-paper px-2 py-1">
                    <option value="">Pick a post</option>
                    @foreach ($proofPosts as $p)<option value="{{ $p->id }}">{{ $p->created_at->timezone(config('stoop.timezone'))->format('D') }} · {{ str($p->body)->limit(70) }}</option>@endforeach
                </select>
            @endif
            @error('proofId') <p class="text-flag">{{ $message }}</p> @enderror
            <div class="flex gap-2"><button class="rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Mark it</button><button type="button" wire:click="$set('marking', null)" class="rounded-lg border border-rule px-3 py-1.5">Cancel</button></div>
        </form>
    @endif
</div>
