<div wire:poll.30s.visible class="mt-4 grid gap-3 text-sm md:grid-cols-2">
    {{-- Next trains, styled like the arrival boards on Metro platforms. --}}
    <section class="overflow-hidden rounded-xl bg-[#121212] text-[#f5b335] shadow-inner" aria-labelledby="trains-{{ $code }}">
        <div class="flex items-center justify-between border-b border-white/10 px-3.5 py-2">
            <h2 id="trains-{{ $code }}" class="font-mono text-[11px] font-semibold uppercase tracking-[0.18em] text-white/70">Next trains</h2>
            @if (is_array($trains) && $trains)
                <span class="flex items-center gap-1.5 font-mono text-[10px] uppercase tracking-wider text-white/50"><span class="size-1.5 animate-pulse rounded-full bg-[#00b140] motion-reduce:animate-none"></span>Live</span>
            @endif
        </div>

        @if (is_array($trains) && $trains)
            <table class="w-full font-mono text-[13px]">
                <thead class="text-[10px] uppercase tracking-wider text-white/40">
                    <tr><th class="px-3.5 pt-2 text-left font-normal">Ln</th><th class="pt-2 text-left font-normal">Dest</th><th class="px-3.5 pt-2 text-right font-normal">Min</th></tr>
                </thead>
                <tbody>
                    @foreach ($trains as $t)
                        <tr>
                            <td class="px-3.5 py-1"><span class="inline-block h-3 w-6 rounded-sm align-middle" style="background: {{ $t['line'] ? config("stoop.lines.{$t['line']}.color") : '#5d6366' }}" title="{{ ucfirst($t['line'] ?? '') }} line"></span></td>
                            <td class="py-1 uppercase">{{ $t['destination'] }}</td>
                            <td class="px-3.5 py-1 text-right font-bold tabular-nums">{{ $t['minutes'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="px-3.5 pb-2 pt-1 font-mono text-[10px] text-white/40">ARR arriving · BRD boarding · updates every 30 seconds</p>
        @elseif (is_array($trains))
            <p class="px-3.5 py-4 font-mono text-[13px] uppercase">No trains predicted</p>
        @else
            <div class="px-3.5 py-4">
                <p class="font-mono text-[13px] uppercase tracking-wide">-- -- -- &nbsp; --</p>
                <p class="mt-1 text-[12px] text-white/50">Live arrivals aren’t connected yet.@if (auth()->user()?->is_staff) Add a WMATA_API_KEY to turn them on.@endif</p>
            </div>
        @endif
    </section>

    {{-- Capital Bikeshare docks, nearest first, with walking time. --}}
    <section class="rounded-xl border border-rule bg-card" aria-labelledby="bikes-{{ $code }}">
        <div class="flex items-center justify-between border-b border-rule px-3.5 py-2">
            <h2 id="bikes-{{ $code }}" class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">
                <svg viewBox="0 0 24 24" class="size-4 text-[#d22630]" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="5.5" cy="16.5" r="3.5" /><circle cx="18.5" cy="16.5" r="3.5" /><path d="M5.5 16.5 9 9h6l3.5 7.5M9 9l3 7.5h-6.5M14 6h2.5" /></svg>
                Capital Bikeshare
            </h2>
            <span class="text-[11px] text-mute">within a 6-minute walk</span>
        </div>

        @if ($bikes === null)
            <p class="px-3.5 py-4 text-mute">Bikeshare info isn’t available right now.</p>
        @elseif ($bikes === [])
            <p class="px-3.5 py-4 text-mute">No docks within a few blocks.</p>
        @else
            <ul class="divide-y divide-rule">
                @foreach ($bikes as $b)
                    @php($walk = max(1, (int) round($b['meters'] / 80)))
                    @php($classic = $b['bikes'] - $b['ebikes'])
                    <li class="px-3.5 py-2.5">
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="truncate font-medium">{{ $b['name'] }}</span>
                            <span class="shrink-0 text-[12px] text-mute">{{ $walk }} min walk</span>
                        </div>
                        <div class="mt-1.5 flex flex-wrap gap-1.5 text-[12px]">
                            <span @class(['rounded-full px-2 py-0.5 tabular-nums', 'bg-chip' => $classic, 'bg-chip text-mute' => ! $classic])><b>{{ $classic }}</b> {{ str('bike')->plural($classic) }}</span>
                            <span @class(['rounded-full px-2 py-0.5 tabular-nums', 'bg-[#009cde]/12 text-[#007bb0] dark:text-[#5cc5f0]' => $b['ebikes'], 'bg-chip text-mute' => ! $b['ebikes']])>⚡ <b>{{ $b['ebikes'] }}</b> {{ str('e-bike')->plural($b['ebikes']) }}</span>
                            <span @class(['rounded-full px-2 py-0.5 tabular-nums', 'bg-chip' => $b['docks'], 'bg-flag/10 text-flag' => ! $b['docks']])><b>{{ $b['docks'] }}</b> open {{ str('dock')->plural($b['docks']) }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($inDc && $ward)
        {{-- DC 311 requests nearby, from DC's open data. --}}
        <section class="rounded-xl border border-rule bg-card md:col-span-2" aria-labelledby="dc311-{{ $code }}">
            <div class="flex items-center justify-between border-b border-rule px-3.5 py-2">
                <h2 id="dc311-{{ $code }}" class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.13em] text-mute"><flux:icon.wrench-screwdriver variant="micro" />Nearby service requests</h2>
                <span class="text-[11px] text-mute">DC 311 · last 90 days</span>
            </div>
            @if ($requests === null)
                <p class="px-3.5 py-4 text-mute">DC 311 data isn’t available right now.</p>
            @elseif ($requests === [])
                <p class="px-3.5 py-4 text-mute">No 311 requests within a few blocks lately.</p>
            @else
                <ul class="divide-y divide-rule">
                    @foreach ($requests as $r)
                        <li class="flex items-baseline justify-between gap-2 px-3.5 py-2">
                            <span class="min-w-0"><b class="font-medium">{{ $r['type'] }}</b> <span class="text-mute">· {{ $r['address'] }}</span></span>
                            <span class="shrink-0 text-[12px] text-mute">{{ $r['status'] }}@if ($r['opened_at']) · {{ \Illuminate\Support\Carbon::parse($r['opened_at'])->shortAbsoluteDiffForHumans() }}@endif</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <p class="flex flex-wrap gap-x-4 gap-y-1 text-[13px] text-mute md:col-span-2">
            <span class="inline-flex items-center gap-1 font-semibold text-ink"><flux:icon.map variant="micro" />Ward {{ $ward }} · ANC {{ $anc }}</span>
            <a href="{{ \App\Support\Neighborhoods::ancUrl($anc) }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 hover:text-ink"><flux:icon.building-library variant="micro" />ANC {{ $anc }} meetings</a>
            <a href="https://311.dc.gov/" target="_blank" rel="noopener" class="inline-flex items-center gap-1 hover:text-ink"><flux:icon.wrench-screwdriver variant="micro" />Report a problem to DC 311</a>
            <a href="{{ config('neighborhoods.pepco_outage_map') }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 hover:text-ink"><flux:icon.bolt variant="micro" />Pepco outage map</a>
        </p>
    @elseif (\App\Support\Neighborhoods::pepco($code))
        <p class="text-[13px] text-mute md:col-span-2">
            <a href="{{ config('neighborhoods.pepco_outage_map') }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 hover:text-ink"><flux:icon.bolt variant="micro" />Pepco outage map</a>
        </p>
    @endif
</div>
