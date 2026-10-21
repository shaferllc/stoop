<div>
    <h1 class="mb-2 font-display text-3xl font-bold">Places</h1>
    @include('partials.admin-nav')

    @php($sales = auth()->user()->hasRole('sales'))
    <div class="mb-3 flex gap-2 text-sm">
        <button type="button" wire:click="$set('tab', 'places')" @class(['rounded-full border px-3 py-0.5', 'border-ink' => $tab === 'places', 'border-transparent bg-chip text-mute' => $tab !== 'places'])>Places</button>
        @if ($sales)<button type="button" wire:click="$set('tab', 'claims')" @class(['rounded-full border px-3 py-0.5', 'border-ink' => $tab === 'claims', 'border-transparent bg-chip text-mute' => $tab !== 'claims'])>Claims ({{ $claims->count() }})</button>@endif
    </div>

    @if ($tab === 'claims' && $sales)
        <div class="space-y-3">
            @forelse ($claims as $c)
                <div wire:key="claim-{{ $c->id }}" class="rounded-xl border border-rule bg-card p-4 text-sm">
                    <p><a href="{{ route('admin.users.show', $c->user) }}" wire:navigate class="font-semibold">{{ '@'.$c->user->username }}</a> ({{ $c->user->email }}) says they’re <b>{{ $c->role }}</b> at <a href="{{ route('places.show', $c->place) }}" class="font-semibold underline">{{ $c->place->name }}</a>.</p>
                    <p class="mt-1 rounded-lg bg-chip px-3 py-2">{{ $c->evidence }}</p>
                    <p class="mt-1 text-mute">Check it before approving: call the business, or confirm the email domain matches its website.</p>
                    <div class="mt-2 flex gap-2">
                        <button type="button" wire:click="decideClaim({{ $c->id }}, true)" class="rounded-lg bg-flag px-3 py-1 font-semibold text-white">Approve</button>
                        <button type="button" wire:click="decideClaim({{ $c->id }}, false)" class="rounded-lg border border-rule px-3 py-1">Deny</button>
                    </div>
                </div>
            @empty
                <div class="rounded-xl border border-dashed border-rule p-8 text-center text-mute">No claims to check.</div>
            @endforelse
        </div>
    @else
        <div class="grid gap-3 lg:grid-cols-2">
            @if ($sales)<form wire:submit="import" class="rounded-xl border border-rule bg-card p-4 text-sm">
                <h2 class="font-semibold">Import a list</h2>
                <p class="mt-1 text-mute">CSV with a header row. Columns: <code class="font-mono text-[12px]">{{ implode(', ', \App\Livewire\Admin\Places::COLUMNS) }}</code>. Only <code>name</code> is required. Re-importing the same list updates it.</p>
                <input type="file" wire:model="csv" accept=".csv,text/csv" class="mt-2 block text-sm">
                @error('csv') <p class="mt-1 text-flag">{{ $message }}</p> @enderror
                <button class="mt-2 rounded-lg bg-flag px-3 py-1.5 font-semibold text-white" wire:loading.attr="disabled">Import</button>
            </form>@endif
            <div class="flex flex-col gap-2 rounded-xl border border-rule bg-card p-4 text-sm">
                <input type="search" wire:model.live.debounce.300ms="q" placeholder="Find a place" aria-label="Find a place" class="rounded-lg border border-rule bg-paper px-3 py-1.5">
                <button type="button" wire:click="edit" class="self-start rounded-lg border border-rule px-3 py-1.5">Add a place</button>
            </div>
        </div>

        @if ($editing !== null)
            <form wire:submit="save" class="mt-3 grid gap-2 rounded-xl border border-ink bg-card p-4 text-sm sm:grid-cols-2">
                <h2 class="font-semibold sm:col-span-2">{{ $editing ? 'Edit place' : 'New place' }}</h2>
                @foreach (['name' => 'Name', 'neighborhood' => 'Neighborhood', 'address' => 'Address', 'website' => 'Website', 'list_name' => 'List', 'list_rank' => 'Rank on list', 'article_url' => 'Magazine article URL', 'reservation_url' => 'Reservation link (OpenTable, Resy, Tock or own site)'] as $field => $label)
                    <label>{{ $label }}<input wire:model="form.{{ $field }}" class="mt-0.5 block w-full rounded-md border border-rule bg-paper px-2 py-1">@error("form.$field")<span class="text-flag">{{ $message }}</span>@enderror</label>
                @endforeach
                <label>Kind<select wire:model="form.category" class="mt-0.5 block w-full rounded-md border border-rule bg-paper px-2 py-1"><option value="">None</option>@foreach (\App\Models\Place::CATEGORIES as $c)<option>{{ $c }}</option>@endforeach</select></label>
                <label>Station<select wire:model="form.stop" class="mt-0.5 block w-full rounded-md border border-rule bg-paper px-2 py-1"><option value="">None</option>@foreach (config('stoop.stations') as $code => $n)<option value="{{ $code }}">{{ $code }} · {{ $n }}</option>@endforeach</select></label>
                <label class="sm:col-span-2">Blurb<textarea wire:model="form.blurb" rows="2" class="mt-0.5 block w-full rounded-md border border-rule bg-paper px-2 py-1"></textarea></label>
                <div class="flex gap-2 sm:col-span-2"><button class="rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Save</button><button type="button" wire:click="$set('editing', null)" class="rounded-lg border border-rule px-3 py-1.5">Cancel</button></div>
            </form>
        @endif

        <div class="mt-3 overflow-x-auto rounded-xl border border-rule bg-card">
            <table class="w-full text-sm">
                <thead class="text-left text-[11px] uppercase tracking-wide text-mute"><tr><th class="px-4 py-2">Place</th><th class="px-4 py-2">List</th><th class="px-4 py-2">Posts</th><th class="px-4 py-2">Owner</th><th></th></tr></thead>
                <tbody class="divide-y divide-rule">
                    @foreach ($places as $p)
                        <tr wire:key="p-{{ $p->id }}">
                            <td class="px-4 py-2"><a href="{{ route('places.show', $p) }}" class="font-semibold hover:underline">{{ $p->name }}</a> <span class="text-mute">{{ $p->neighborhood }}</span></td>
                            <td class="px-4 py-2 text-mute">{{ $p->listLabel() }}</td>
                            <td class="px-4 py-2 tabular-nums">{{ $p->dispatches_count }}</td>
                            <td class="px-4 py-2">{{ $p->owner ? '@'.$p->owner->username : '' }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right"><button type="button" wire:click="edit({{ $p->id }})" class="underline">Edit</button> @if ($sales)<button type="button" wire:click="delete({{ $p->id }})" wire:confirm="Delete {{ $p->name }}? Posts that tag it stay but lose the tag." class="ml-2 text-flag underline">Delete</button>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $places->links() }}</div>
    @endif
</div>
