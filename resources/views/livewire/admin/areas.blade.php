<div>
    <h1 class="mb-2 font-display text-3xl font-bold">Area reports</h1>
    @include('partials.admin-nav')
    <p class="text-sm text-mute">Monthly reports on a group of stations for business improvement districts and developers. Same rules as Reader insights: no names or posts, and any number from fewer than {{ \App\Support\ReaderInsights::K }} people (or sales) shows as {{ \App\Support\ReaderInsights::SUPPRESSED }}.</p>

    <div class="mt-3 flex flex-wrap items-end gap-2 text-sm">
        <label>Month<input type="month" wire:model.live="month" class="mt-0.5 block rounded-md border border-rule bg-paper px-2 py-1"></label>
        <label>Share links last<select wire:model="days" class="mt-0.5 block rounded-md border border-rule bg-paper px-2 py-1">@foreach ([1, 7, 14, 30] as $d)<option value="{{ $d }}">{{ $d }} {{ str('day')->plural($d) }}</option>@endforeach</select></label>
        <button type="button" wire:click="edit" class="rounded-lg border border-rule px-3 py-1.5">Add an area</button>
    </div>
    @error('month') <p class="text-sm text-flag">{{ $message }}</p> @enderror

    @if ($editing !== null)
        <form wire:submit="save" class="mt-3 space-y-2 rounded-xl border border-ink bg-card p-4 text-sm">
            <label class="block">Name<input wire:model="form.name" maxlength="80" class="mt-0.5 block w-full rounded-md border border-rule bg-paper px-2 py-1"></label>
            <label class="block">Stations (hold ⌘ or Ctrl for several)
                <select wire:model="form.stations" multiple size="8" class="mt-0.5 block w-full rounded-md border border-rule bg-paper px-2 py-1">
                    @foreach (collect(config('stoop.stations'))->sort() as $code => $name)<option value="{{ $code }}">{{ $name }} ({{ $code }})</option>@endforeach
                </select>
            </label>
            <label class="flex items-center gap-2"><input type="checkbox" wire:model="form.verify"> Needs checking (boundaries not verified)</label>
            @foreach (['form.name', 'form.stations', 'form.stations.*'] as $f) @error($f) <p class="text-flag">{{ $message }}</p> @enderror @endforeach
            <div class="flex gap-2"><button class="rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Save</button><button type="button" wire:click="$set('editing', null)" class="rounded-lg border border-rule px-3 py-1.5">Cancel</button></div>
        </form>
    @endif

    <div class="mt-3 space-y-2">
        @foreach ($areas as $area)
            <div wire:key="area-{{ $area->id }}" class="rounded-xl border border-rule bg-card p-4 text-sm">
                <div class="flex flex-wrap items-baseline gap-2">
                    <b class="text-base">{{ $area->name }}</b>
                    @if ($area->verify)<span class="rounded bg-flag px-1 text-[10px] font-bold text-white">VERIFY</span>@endif
                    <span class="text-mute">{{ $area->stationNames() }}</span>
                    <span class="ml-auto whitespace-nowrap">
                        <a href="{{ route('admin.areas.print', [$area, $month]) }}" target="_blank" class="underline">Printable (PDF)</a>
                        <a href="{{ route('admin.areas.csv', [$area, $month]) }}" class="ml-2 underline">CSV</a>
                        <button type="button" wire:click="share({{ $area->id }})" class="ml-2 underline">Share link</button>
                        <button type="button" wire:click="edit({{ $area->id }})" class="ml-2 underline">Edit</button>
                        <button type="button" wire:click="delete({{ $area->id }})" wire:confirm="Delete {{ $area->name }}? Share links to it stop working." class="ml-2 text-flag underline">Delete</button>
                    </span>
                </div>
                @if ($links[$area->id] ?? null)
                    <div class="mt-2">
                        <input readonly value="{{ $links[$area->id] }}" aria-label="Share link" onclick="this.select()" class="w-full rounded-md border border-rule bg-paper px-2 py-1 font-mono text-[12px]">
                        <p class="mt-0.5 text-[12px] text-mute">Works without a Stoop login for {{ $days }} {{ str('day')->plural($days) }}. Anyone with the link can open it; it can’t be revoked early.</p>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</div>
