<div>
    <h1 class="mb-2 font-display text-3xl font-bold">Referrals</h1>
    @include('partials.admin-nav')
    <p class="text-sm text-mute">Clicks on Reserve and Get tickets, by provider and DC day.
        Partner links on: {{ $earning->map(fn ($p) => \App\Support\Referrals::name($p))->join(', ') ?: 'none yet (set OPENTABLE_PARTNER_ID, RESY_AFFILIATE_ID, TOCK_AFFILIATE_ID or EVENTBRITE_AFFILIATE_ID)' }}.</p>

    <div class="mt-3 flex flex-wrap items-end gap-2 text-sm">
        <label>From<input type="date" wire:model.live="from" class="mt-0.5 block rounded-md border border-rule bg-paper px-2 py-1"></label>
        <label>To<input type="date" wire:model.live="to" class="mt-0.5 block rounded-md border border-rule bg-paper px-2 py-1"></label>
        <a href="{{ route('admin.referrals.csv', ['from' => $period[0], 'to' => $period[1]]) }}" class="rounded-lg border border-rule px-3 py-1.5">Download CSV</a>
        <span class="text-mute">{{ $period[0] }} to {{ $period[1] }}</span>
    </div>

    <div class="mt-4 grid gap-3 lg:grid-cols-2">
        <section class="rounded-xl border border-rule bg-card p-4 text-sm">
            <h2 class="font-display text-lg font-bold">By provider</h2>
            @forelse ($providers as $provider => $clicks)
                <div class="flex justify-between border-b border-rule py-1 last:border-0"><span>{{ \App\Support\Referrals::name($provider) }}</span><b class="tabular-nums">{{ number_format($clicks) }}</b></div>
            @empty
                <p class="text-mute">No clicks in this period.</p>
            @endforelse
        </section>
        <section class="rounded-xl border border-rule bg-card p-4 text-sm">
            <h2 class="font-display text-lg font-bold">Top places and happenings</h2>
            @forelse ($top as $row)
                <div class="flex justify-between gap-2 border-b border-rule py-1 last:border-0"><span>{{ $row['name'] }} <span class="text-mute">· {{ \App\Support\Referrals::name($row['provider']) }}</span></span><b class="tabular-nums">{{ number_format($row['clicks']) }}</b></div>
            @empty
                <p class="text-mute">No clicks in this period.</p>
            @endforelse
        </section>
    </div>

    <div class="mt-3 overflow-x-auto rounded-xl border border-rule bg-card">
        <table class="w-full text-sm">
            <thead class="text-left text-[11px] uppercase tracking-wide text-mute"><tr><th class="px-4 py-2">Day</th><th class="px-4 py-2">Provider</th><th class="px-4 py-2 text-right">Clicks</th></tr></thead>
            <tbody class="divide-y divide-rule">
                @foreach ($days as $row)
                    <tr><td class="px-4 py-1.5 tabular-nums">{{ $row->day }}</td><td class="px-4 py-1.5">{{ \App\Support\Referrals::name($row->provider) }}</td><td class="px-4 py-1.5 text-right tabular-nums">{{ number_format($row->clicks) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
