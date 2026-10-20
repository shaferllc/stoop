<div>
    @php($tabs = ['following' => 'Following', 'rush' => 'Rush Hour', 'latest' => 'Latest', 'near' => 'Near me'])

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

    <livewire:composer />

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

    <div class="mt-3 space-y-2.5">
        @forelse ($this->dispatches as $dispatch)
            <x-dispatch-card :dispatch="$dispatch" :state="$state" />
        @empty
            <div class="rounded-xl border border-dashed border-rule p-8 text-center text-mute">
                {{ $tab === 'following' ? 'Follow some neighbors and their dispatches will show up here.' : 'Nothing here yet. File the first dispatch.' }}
            </div>
        @endforelse
    </div>

    @if ($this->dispatches->count() >= $limit)
        <button type="button" wire:click="loadMore" class="mt-3 w-full rounded-lg border border-rule py-2 text-sm text-mute hover:text-ink">Load more</button>
    @endif

    @include('partials.report-dialog')
    @include('partials.undo-bar')
</div>
