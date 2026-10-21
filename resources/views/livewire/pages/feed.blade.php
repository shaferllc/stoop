<div wire:poll.60s.visible
    x-data="{ x: null }"
    x-on:touchstart="x = $event.touches[0].clientX"
    x-on:touchend="
        if (x === null) return;
        const dx = $event.changedTouches[0].clientX - x; x = null;
        if (Math.abs(dx) < 80 || $event.target.closest('input, textarea, select, [role=tablist]')) return;
        const order = @js(auth()->check() ? ['following', 'rush', 'latest', 'near'] : ['rush', 'latest']);
        const next = order[order.indexOf(@js($tab)) + (dx < 0 ? 1 : -1)];
        if (next) $wire.setTab(next);
    ">
    @php($tabs = ['following' => 'Following', 'rush' => 'Rush Hour', 'latest' => 'Latest', 'near' => 'Near me'])

    @if ($stop)
        <div class="mb-3 rounded-xl border border-rule bg-card p-4">
            @php($mayor = \App\Support\Stations::mayorOf($stop))
            <div class="flex items-center gap-1.5">
                <span class="font-mono text-[12px] text-mute">{{ $stop }}</span>
                @foreach (\App\Support\Stations::linesAt($stop) as $l)
                    <span class="h-1.5 w-5 rounded-full" style="background: {{ config("stoop.lines.$l.color") }}" title="{{ ucfirst($l) }} line"></span>
                @endforeach
            </div>
            @php($crown = \App\Support\Rivalries::crownFor($stop))
            <h1 class="font-display text-2xl font-bold">{{ config("stoop.stations.$stop") }} @if ($crown)<span title="Won last week: {{ $crown['title'] }}">👑</span>@endif</h1>
            @if ($crown)<p class="text-sm font-semibold text-flag">Won last week’s rivalry: {{ $crown['title'] }}, {{ implode('–', array_values($crown['stops'])) }}.</p>@endif
            <p class="text-sm text-mute">
                @if ($mayor)
                    Mayor: <a href="{{ route('users.show', $mayor) }}" wire:navigate class="font-semibold text-ink">{{ '@'.$mayor->username }}</a>, the most helpful neighbor here this month.
                @else
                    No mayor yet. Post and answer questions here to claim it.
                @endif
            </p>
            <a href="{{ route('feeds.stop', $stop) }}" class="text-[12.5px] text-mute underline">Follow this stop in a feed reader</a>
        </div>
    @endif

    <div class="mb-3 flex gap-0.5 overflow-x-auto border-b border-rule" role="tablist">
        @foreach ($tabs as $key => $label)
            <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="setTab('{{ $key }}')" @class([
                'whitespace-nowrap border-b-2 px-3.5 py-2 text-sm font-medium',
                'border-flag text-ink' => $tab === $key,
                'border-transparent text-mute hover:text-ink' => $tab !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    <div class="mb-3 flex gap-1.5 overflow-x-auto lg:hidden">
        @foreach (config('stoop.lines') as $key => $l)
            <a href="{{ route('home', ['line' => $line === $key ? null : $key]) }}" wire:navigate @class(['flex shrink-0 items-center rounded-full border px-2.5 py-0.5 text-[12.5px]', 'border-ink' => $line === $key, 'border-transparent bg-chip text-mute' => $line !== $key])>
                <span class="mr-1.5 size-2 rounded-full" style="background: {{ $l['color'] }}"></span>{{ $l['name'] }}
            </a>
        @endforeach
    </div>

    @if ($sponsorship)
        <a href="{{ route('sponsorships.click', $sponsorship) }}" target="_blank" rel="sponsored noopener" class="mb-3 block rounded-xl border border-rule bg-card p-3.5 hover:border-ink">
            <div class="text-[11px] font-semibold uppercase tracking-wide text-mute">Sponsored · {{ $sponsorship->sponsor }}</div>
            <div class="font-display text-lg font-bold">{{ $sponsorship->headline }}</div>
            @if ($sponsorship->body)<p class="text-sm text-mute">{{ $sponsorship->body }}</p>@endif
        </a>
    @endif

    <div class="hidden lg:block"><livewire:composer /></div>

    @if ($line || $stop || trim($q) !== '')
        <div class="mt-3 flex flex-wrap items-center gap-2 text-[13px] text-mute">
            @if ($line) Line: <b class="text-ink">{{ config("stoop.lines.$line.name") }}</b> @endif
            @if ($stop) Stop: <b class="text-ink">{{ $stop }} · {{ config("stoop.stations.$stop") }}</b> @endif
            @if (trim($q) !== '') Search: <b class="text-ink">{{ $q }}</b> @endif
            <a href="{{ route('home') }}" wire:navigate class="rounded-full border border-rule px-2.5">Clear</a>
        </div>
    @endif

    @if ($tab === 'near' && ! auth()->user()?->stops)
        <div class="mt-3 rounded-xl border border-dashed border-rule p-6 text-center text-mute">
            Pick up to three stations in <a href="{{ route('profile.edit') }}" wire:navigate class="underline">Settings</a> to see what’s happening near you.
        </div>
    @endif

    @if ($this->newCount)
        <button type="button" wire:click="showNew" class="mt-3 w-full rounded-lg bg-ink py-2 text-sm font-semibold text-paper">
            Show {{ $this->newCount }} new {{ str('dispatch')->plural($this->newCount) }}
        </button>
    @endif

    <div class="mt-3 space-y-2.5">
        @forelse ($this->dispatches as $dispatch)
            <x-dispatch-card :dispatch="$dispatch" :translated="$translations[$dispatch->id] ?? null" :state="$state" />
        @empty
            @if ($tab === 'following')
                <x-empty title="Nobody on this platform yet">Follow some neighbors and their dispatches will pull in here.</x-empty>
            @elseif ($stop)
                <x-empty :title="'Quiet at '.config('stoop.stations.'.$stop)">Nobody has posted from this stop yet. Be the first.</x-empty>
            @else
                <x-empty title="Nothing here yet">The first dispatch is always the hardest. File one.</x-empty>
            @endif
            @if ($tab === 'following')
                <div class="space-y-2">
                    @foreach ($this->suggestedPeople as $person)
                        <div wire:key="suggest-{{ $person->id }}" class="flex items-center gap-3 rounded-xl border border-rule bg-card p-3">
                            <div class="grid size-9 place-items-center rounded-full text-xs font-bold text-white" style="background: {{ $person->color() }}">{{ $person->initials() }}</div>
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('users.show', $person) }}" wire:navigate class="font-semibold">{{ $person->name }}</a>
                                <div class="text-[12.5px] text-mute">{{ '@'.$person->username }} · {{ $person->reason }}</div>
                            </div>
                            <button type="button" wire:click="toggleFollow({{ $person->id }})" class="rounded-full border border-rule px-3 py-1 text-sm font-semibold">Follow</button>
                        </div>
                    @endforeach
                </div>
            @endif
        @endforelse
    </div>

    @if ($this->dispatches->count() >= $limit)
        <button type="button" wire:click="loadMore" class="mt-3 w-full rounded-lg border border-rule py-2 text-sm text-mute hover:text-ink">Load more</button>
    @endif

    @include('partials.report-dialog')
    @include('partials.undo-bar')
</div>
