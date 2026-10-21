@php($tz = config('stoop.timezone'))
<div>
    <h1 class="mb-2 font-display text-3xl font-bold">Magazine events</h1>
    @include('partials.admin-nav')

    <p class="mb-3 text-sm text-mute">Things-to-do listings from the magazine’s weekly roundups post as Happenings from {{ '@'.config('stoop.magazine_account') }}, every hour. The site has no events feed, so each listing’s day is read from its note, like “(Sat, $35, Shaw)”; listings without a readable day are skipped. An event already posted is never posted again, but its start time moves if the listing changes.</p>

    <div class="mb-3 grid gap-3 lg:grid-cols-2">
        <section class="rounded-xl border border-rule bg-card p-4 text-sm">
            <h2 class="font-semibold">Import status</h2>
            @if ($status)
                <p class="mt-1">Last run {{ \Illuminate\Support\Carbon::parse($status['at'])->timezone($tz)->format('M j, g:ia') }}: {{ $status['posted'] }} posted, {{ $status['updated'] }} updated, {{ $status['skipped'] }} skipped.</p>
                @if ($status['error'])<p class="mt-1 text-flag">{{ $status['error'] }}</p>@endif
            @else
                <p class="mt-1 text-mute">Hasn’t run yet.</p>
            @endif
            <button wire:click="importNow" wire:loading.attr="disabled" class="mt-2 rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Import now</button>
        </section>

        <form wire:submit="upload" class="rounded-xl border border-rule bg-card p-4 text-sm">
            <h2 class="font-semibold">Upload a CSV</h2>
            <p class="mt-1 text-mute">Header row with <code class="font-mono text-[12px]">title, date, time, venue, url, description</code>. <code>title</code>, <code>date</code> (YYYY-MM-DD) and an https <code>url</code> are required; <code>time</code> is DC time, like 7:30pm. Venues that name a known place or station get its stop.</p>
            <input type="file" wire:model="csv" accept=".csv,text/csv" class="mt-2 block text-sm">
            @error('csv') <p class="mt-1 text-flag">{{ $message }}</p> @enderror
            <button class="mt-2 rounded-lg border border-rule px-3 py-1.5 font-semibold" wire:loading.attr="disabled">Import CSV</button>
        </form>
    </div>

    <section class="rounded-xl border border-rule bg-card p-4 text-sm">
        <h2 class="font-semibold">Recently imported</h2>
        <ul class="mt-2 divide-y divide-rule">
            @forelse ($events as $e)
                <li class="py-2">
                    @if ($e->dispatch_id)<a href="{{ route('dispatches.show', $e->dispatch_id) }}" class="font-semibold underline">{{ $e->title }}</a>@else<span class="font-semibold">{{ $e->title }}</span> <span class="text-mute">(post removed)</span>@endif
                    <span class="text-mute">· {{ \Illuminate\Support\Carbon::parse($e->starts_at)->timezone($tz)->format('D M j') }} · {{ $e->source === 'csv' ? 'CSV' : 'feed' }}</span>
                </li>
            @empty
                <li class="py-2 text-mute">Nothing imported yet.</li>
            @endforelse
        </ul>
    </section>
</div>
