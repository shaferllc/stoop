<div>
    <h1 class="mb-2 font-display text-3xl font-bold">Archive</h1>
    @include('partials.admin-nav')

    <p class="mb-3 text-sm text-mute">Older magazine stories for “From the archive” on station pages ({{ number_format($total) }} so far). Stories are tagged to stations by station and neighborhood names in their title and description, using the alias list below. New articles from the feed are tagged the same way.</p>

    <div class="mb-3 grid gap-3 lg:grid-cols-2">
        <form wire:submit="import" class="rounded-xl border border-rule bg-card p-4 text-sm">
            <h2 class="font-semibold">Import stories</h2>
            <p class="mt-1 text-mute">CSV with a header row. Columns: <code class="font-mono text-[12px]">{{ implode(', ', \App\Support\Archive::COLUMNS) }}</code>. <code>title</code> and <code>url</code> are required. Leave <code>stations</code> empty (or give codes like E02) to tag automatically. Re-importing a URL updates it.</p>
            <input type="file" wire:model="csv" accept=".csv,text/csv" class="mt-2 block text-sm">
            @error('csv') <p class="mt-1 text-flag">{{ $message }}</p> @enderror
            <button class="mt-2 rounded-lg bg-flag px-3 py-1.5 font-semibold text-white" wire:loading.attr="disabled">Import</button>
        </form>

        <section class="rounded-xl border border-rule bg-card p-4 text-sm">
            <h2 class="font-semibold">Aliases</h2>
            <p class="mt-1 text-mute">Neighborhoods, landmarks and spellings that point to a station. Case matters: “Shaw”, not “shaw”.</p>
            <form wire:submit="addAlias" class="mt-2 flex flex-wrap gap-2">
                <input wire:model="alias" maxlength="80" placeholder="Adams Morgan" aria-label="Name" class="min-w-0 flex-1 rounded-md border border-rule bg-paper px-2 py-1">
                <select wire:model="aliasStop" aria-label="Station" class="rounded-md border border-rule bg-paper px-1.5 py-1">
                    <option value="">Station</option>
                    @foreach (collect(config('stoop.stations'))->sort() as $code => $name)
                        <option value="{{ $code }}">{{ $name }} ({{ $code }})</option>
                    @endforeach
                </select>
                <button class="rounded-lg border border-rule px-3 py-1">Add</button>
            </form>
            @error('alias') <p class="mt-1 text-flag">{{ $message }}</p> @enderror
            @error('aliasStop') <p class="mt-1 text-flag">{{ $message }}</p> @enderror
            <div class="mt-2 flex max-h-48 flex-wrap gap-1.5 overflow-y-auto">
                @foreach ($aliases as $a)
                    <span wire:key="a-{{ $a->id }}" class="rounded-full bg-chip px-2.5 py-0.5">{{ $a->alias }} → {{ $a->stop }} <button type="button" wire:click="removeAlias({{ $a->id }})" aria-label="Remove {{ $a->alias }}" class="text-mute">×</button></span>
                @endforeach
            </div>
            <button type="button" wire:click="retag" class="mt-2 rounded-lg border border-rule px-3 py-1">Tag all stories again</button>
        </section>
    </div>

    <input type="search" wire:model.live.debounce.300ms="q" placeholder="Find a story, or a station code" aria-label="Find a story" class="mb-2 w-full rounded-lg border border-rule bg-paper px-3 py-1.5 text-sm">
    <div class="overflow-x-auto rounded-xl border border-rule bg-card">
        <table class="w-full text-sm">
            <thead class="text-left text-[11px] uppercase tracking-wide text-mute"><tr><th class="px-4 py-2">Story</th><th class="px-4 py-2">Date</th><th class="px-4 py-2">Stations</th><th class="px-4 py-2">Neighborhoods</th></tr></thead>
            <tbody class="divide-y divide-rule">
                @forelse ($stories as $s)
                    <tr wire:key="s-{{ $s->id }}">
                        <td class="px-4 py-2"><a href="{{ $s->url }}" target="_blank" rel="noopener" class="font-semibold hover:underline">{{ $s->title }}</a> <span class="text-mute">{{ $s->section }}</span></td>
                        <td class="px-4 py-2 whitespace-nowrap text-mute">{{ $s->published_on }}</td>
                        <td class="px-4 py-2 font-mono text-[12px]">{{ implode(' ', $stations[$s->id] ?? []) ?: '—' }}</td>
                        <td class="px-4 py-2 text-mute">{{ $s->neighborhoods }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-mute">No stories yet. Import a CSV above.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $stories->links() }}</div>
</div>
