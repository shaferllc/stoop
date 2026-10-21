<div class="space-y-3">
    <div>
        <h1 class="font-display text-3xl font-bold">Lost &amp; found</h1>
        <p class="text-mute">Lost and found pets and things, by the stop they were last seen near. To keep everyone safe, posts never show phone numbers or emails: reply or send a message. Message requests have to be on for people who don’t follow each other.</p>
    </div>

    <div class="flex flex-wrap items-center gap-2 text-sm">
        <div class="flex overflow-hidden rounded-lg border border-rule" role="group" aria-label="Open or reunited">
            <button type="button" wire:click="$set('reunited', false)" @class(['px-3 py-1', 'bg-ink text-paper' => ! $reunited])>Still looking</button>
            <button type="button" wire:click="$set('reunited', true)" @class(['px-3 py-1', 'bg-ink text-paper' => $reunited])>Reunited</button>
        </div>
        <select wire:model.live="kind" aria-label="Pet or item" class="rounded-md border border-rule bg-paper px-1.5 py-1 text-[13px]">
            <option value="">Pets and items</option>
            @foreach (\App\Support\LostAndFound::KINDS as $key => $label)<option value="{{ $key }}">{{ $label }}s</option>@endforeach
        </select>
        <select wire:model.live="line" aria-label="Line" class="rounded-md border border-rule bg-paper px-1.5 py-1 text-[13px]">
            <option value="">All lines</option>
            @foreach (config('stoop.lines') as $key => $l)<option value="{{ $key }}">{{ $l['name'] }}</option>@endforeach
        </select>
        <select wire:model.live="stop" aria-label="Stop" class="rounded-md border border-rule bg-paper px-1.5 py-1 text-[13px]">
            <option value="">All stops</option>
            @foreach (collect(config('stoop.stations'))->sort() as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@endforeach
        </select>
    </div>

    <div class="space-y-2.5">
        @forelse ($dispatches as $dispatch)
            <x-dispatch-card :dispatch="$dispatch" :translated="$translations[$dispatch->id] ?? null" :state="$state" />
        @empty
            <x-empty title="{{ $reunited ? 'No reunions yet' : 'Nothing lost here' }}" art="platform">Lost or found something? Pick “Lost &amp; found” when you post.</x-empty>
        @endforelse
    </div>

    @if ($dispatches->count() >= $limit)
        <button type="button" wire:click="loadMore" class="w-full rounded-lg border border-rule py-2 text-sm text-mute hover:text-ink">Load more</button>
    @endif

    @include('partials.report-dialog')
    @include('partials.undo-bar')
</div>
