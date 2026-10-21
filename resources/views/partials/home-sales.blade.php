{{-- Recent home sales near a station page's stop, from DC property records. Hidden when there are none. --}}
@php($sales = \App\Support\HomeSales::summary($stop))
@if ($sales)
    <section class="mt-3 rounded-xl border border-rule bg-card text-sm" aria-labelledby="sales-{{ $stop }}">
        <div class="flex items-center justify-between border-b border-rule px-3.5 py-2">
            <h2 id="sales-{{ $stop }}" class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">
                <span class="h-1.5 w-4 rounded-full" style="background: {{ config('stoop.lines.green.color') }}"></span>
                Recent sales near {{ config("stoop.stations.$stop") }}
            </h2>
            <span class="text-[11px] text-mute">last 90 days · within 1 km</span>
        </div>
        <p class="px-3.5 pt-2.5">Median <b class="tabular-nums">${{ number_format($sales['median']) }}</b> across <b class="tabular-nums">{{ $sales['count'] }}</b> {{ str('sale')->plural($sales['count']) }}.</p>
        <ul class="divide-y divide-rule">
            @foreach ($sales['recent'] as $sale)
                <li class="flex items-baseline justify-between gap-2 px-3.5 py-2">
                    <span class="truncate">{{ $sale->block }}</span>
                    <span class="shrink-0 tabular-nums"><b>${{ number_format($sale->price) }}</b> <span class="text-[12px] text-mute">{{ \Illuminate\Support\Carbon::parse($sale->sold_on)->format('M j') }}</span></span>
                </li>
            @endforeach
        </ul>
        <p class="px-3.5 pb-2 text-[11px] text-mute">Market sales from the DC Office of Tax and Revenue, rounded to the block.</p>
    </section>
@endif
