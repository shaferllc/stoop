<div>
    <h1 class="mb-2 font-display text-3xl font-bold">Real estate</h1>
    @include('partials.admin-nav')

    <section class="rounded-xl border border-rule bg-card p-4 text-sm">
        <h2 class="font-display text-lg font-bold">Home sales</h2>
        <p class="text-mute">Market sales from DC’s public property records (DC GIS Owner Polygons, no key needed), matched to the nearest station within 1 km and rounded to the block. Imported nightly by <code>stoop:import-home-sales</code>; @homes posts a roundup every Saturday.</p>
        <p class="mt-2">{{ number_format($sales->total) }} sales stored. @if ($sales->newest) Newest sale {{ \Illuminate\Support\Carbon::parse($sales->newest)->format('M j, Y') }}; last import {{ \Illuminate\Support\Carbon::parse($sales->imported)->diffForHumans() }}. @endif</p>
        <button type="button" wire:click="import" wire:loading.attr="disabled" class="mt-2 rounded-lg border border-rule px-3 py-1.5">Import now</button>
    </section>

    <h2 class="mt-5 font-display text-lg font-bold">Open houses</h2>
    <p class="text-sm text-mute">Mark paid open houses as sponsored. The label shows on the post and the listing link gets <code>rel="sponsored"</code>.</p>
    <div class="mt-2 overflow-x-auto rounded-xl border border-rule bg-card">
        <table class="w-full text-sm">
            <thead class="text-left text-[11px] uppercase tracking-wide text-mute"><tr><th class="px-4 py-2">Open house</th><th class="px-4 py-2">Stop</th><th class="px-4 py-2">When</th><th class="px-4 py-2">Agent</th><th></th></tr></thead>
            <tbody class="divide-y divide-rule">
                @forelse ($openHouses as $d)
                    @php($sponsored = $d->details['sponsored'] ?? false)
                    <tr wire:key="oh-{{ $d->id }}">
                        <td class="px-4 py-2"><a href="{{ route('dispatches.show', $d) }}" wire:navigate class="font-semibold underline">{{ $d->details['block'] ?? 'Open house' }}</a><div class="text-mute">{{ '@'.$d->user?->username }} · {{ $d->status }}</div></td>
                        <td class="px-4 py-2">{{ $d->stopName() }}</td>
                        <td class="px-4 py-2">{{ isset($d->details['date']) ? \Illuminate\Support\Carbon::parse($d->details['date'])->format('M j') : '' }}</td>
                        <td class="px-4 py-2">{{ $d->details['agent'] ?? '' }}</td>
                        <td class="whitespace-nowrap px-4 py-2 text-right">
                            @if ($sponsored)<span class="mr-2 rounded bg-chip px-1 text-[10px] font-bold">SPONSORED</span>@endif
                            <button type="button" wire:click="sponsor({{ $d->id }}, {{ $sponsored ? 'false' : 'true' }})" class="underline">{{ $sponsored ? 'Remove sponsored' : 'Mark sponsored' }}</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-6 text-center text-mute">No open houses yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
