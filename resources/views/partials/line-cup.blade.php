{{-- The monthly Line Cup, on the Rivalries page. --}}
<section id="line-cup" class="space-y-3 pt-2">
    <div>
        <h2 class="font-display text-2xl font-bold">The Line Cup</h2>
        <p class="text-mute">Every month the six Metro lines compete. A line scores the helpfulness points its riders earn at its stations: posts from a stop, replies to neighbors there, and Best Answers. Anyone with {{ \App\Support\Rivalries::CUP_REGULAR }} or more points on the winning line is one of its regulars and gets the Line Cup crown 👑.</p>
    </div>
        @foreach ([now(config('stoop.timezone'))->format('F').' so far' => $cup, now(config('stoop.timezone'))->subMonthNoOverflow()->format('F') => $lastCup] as $heading => $cup)
            @php($top = max(1, $cup->max('points')))
            <section class="rounded-xl border border-rule bg-card p-4">
                <h2 class="text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">{{ $heading }}</h2>
                <ol class="mt-2 space-y-2">
                    @foreach ($cup as $line => $row)
                        <li class="flex items-center gap-3 text-sm">
                            <span class="w-4 text-mute tabular-nums">{{ $loop->iteration }}</span>
                            <span class="w-24 shrink-0 capitalize">{{ $line }} line @if ($loop->first && $row['points'] > 0)👑@endif</span>
                            <div class="h-3 flex-1 overflow-hidden rounded-full bg-chip"><div class="h-full rounded-full" style="width: {{ round($row['points'] / $top * 100) }}%; background: {{ config("stoop.lines.$line.color") }}"></div></div>
                            <span class="w-24 text-right text-mute">{{ count($row['regulars']) }} {{ str('regular')->plural(count($row['regulars'])) }}</span>
                            <b class="w-12 text-right tabular-nums">{{ $row['points'] }}</b>
                        </li>
                    @endforeach
                </ol>
            </section>
        @endforeach

        @if ($winners->isNotEmpty())
            <section>
                <h2 class="mb-2 text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">Past winners</h2>
                <ul class="divide-y divide-rule rounded-xl border border-rule bg-card text-sm">
                    @foreach ($winners as $w)
                        <li class="flex items-center gap-3 px-4 py-2.5">
                            <span class="w-28 text-mute">{{ $w['month']->format('F Y') }}</span>
                            <span class="h-2 w-8 rounded-full" style="background: {{ config('stoop.lines.'.$w['line'].'.color') }}"></span>
                            <span class="flex-1 capitalize">{{ $w['line'] }} line</span>
                            <b class="tabular-nums">{{ $w['points'] }}</b>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
</section>
