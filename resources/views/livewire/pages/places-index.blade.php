<div>
    <h1 class="font-display text-3xl font-bold">Places</h1>
    <p class="text-mute">Every spot from the magazine’s lists, with what neighbors say about it.</p>

    <div class="mt-3 flex flex-wrap gap-2 text-sm">
        <select wire:model.live="list" aria-label="List" class="rounded-md border border-rule bg-card px-2 py-1">
            <option value="">All lists</option>
            @foreach ($lists as $l)<option>{{ $l }}</option>@endforeach
        </select>
        <select wire:model.live="category" aria-label="Category" class="rounded-md border border-rule bg-card px-2 py-1">
            <option value="">All kinds</option>
            @foreach (\App\Models\Place::CATEGORIES as $c)<option>{{ $c }}</option>@endforeach
        </select>
        <select wire:model.live="neighborhood" aria-label="Neighborhood" class="rounded-md border border-rule bg-card px-2 py-1">
            <option value="">All neighborhoods</option>
            @foreach ($neighborhoods as $n)<option>{{ $n }}</option>@endforeach
        </select>
    </div>

    <ul class="mt-3 divide-y divide-rule rounded-xl border border-rule bg-card">
        @forelse ($places as $p)
            <li><a href="{{ route('places.show', $p) }}" wire:navigate class="flex items-center gap-3 px-4 py-3 hover:bg-chip/50">
                @if ($p->list_rank)<span class="w-8 shrink-0 font-display text-xl font-bold tabular-nums text-flag">{{ $p->list_rank }}</span>@endif
                <span class="min-w-0 flex-1">
                    <span class="block font-semibold">{{ $p->name }}</span>
                    <span class="block truncate text-[13px] text-mute">{{ collect([$p->category, $p->neighborhood, $p->list_name])->filter()->join(' · ') }}</span>
                </span>
                <span class="text-[13px] text-mute">{{ $p->dispatches_count }} {{ str('post')->plural($p->dispatches_count) }}</span>
            </a></li>
        @empty
            <li class="p-8 text-center text-mute">No places yet. Staff can import the magazine’s lists from the admin.</li>
        @endforelse
    </ul>
</div>
