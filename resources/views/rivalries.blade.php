<x-layouts::stoop title="Rivalries">
    <div class="space-y-4">
        @include('partials.games-nav')
        <div>
            <h1 class="font-display text-3xl font-bold">Rivalries</h1>
            <p class="text-mute">Neighboring stops go head to head each week, scored on helpfulness: posts from the stop, replies to neighbors there, and Best Answers. Winners get a crown on their stop page and a shout-out in the weekly email.</p>
        </div>

        <section>
            <h2 class="mb-2 text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">This week · ends Sunday night</h2>
            <div class="space-y-3">
                @foreach ($current as $m)
                    @php($total = max(1, array_sum($m['stops'])))
                    <div class="rounded-xl border border-rule bg-card p-4">
                        <h3 class="font-display text-lg font-bold">{{ $m['title'] }}</h3>
                        @foreach ($m['stops'] as $code => $points)
                            @php($line = \App\Support\Stations::linesAt($code)[0] ?? 'red')
                            <div class="mt-2 flex items-center gap-3 text-sm">
                                <a href="{{ route('stops.show', $code) }}" class="w-40 shrink-0"><x-station :code="$code" /></a>
                                <div class="h-3 flex-1 overflow-hidden rounded-full bg-chip"><div class="h-full rounded-full" style="width: {{ round($points / $total * 100) }}%; background: {{ config("stoop.lines.$line.color") }}"></div></div>
                                <span class="w-10 text-right font-semibold tabular-nums">{{ $points }}</span>
                                @if ($m['leader'] === $code)<span title="Leading">👑</span>@endif
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </section>

        <section class="rounded-xl border border-rule bg-card p-4">
            <h2 class="text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">Line standings this week</h2>
            <ol class="mt-2 space-y-1.5">
                @foreach ($lines as $line => $row)
                    <li class="flex items-center gap-3 text-sm">
                        <span class="w-4 text-mute tabular-nums">{{ $loop->iteration }}</span>
                        <span class="h-2 w-8 rounded-full" style="background: {{ config("stoop.lines.$line.color") }}"></span>
                        <span class="flex-1 capitalize">{{ $line }} line</span>
                        <span class="text-mute">{{ $row['people'] }} {{ str('neighbor')->plural($row['people']) }}</span>
                        <b class="w-12 text-right tabular-nums">{{ $row['points'] }}</b>
                    </li>
                @endforeach
            </ol>
        </section>

        <section>
            <h2 class="mb-2 text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">Last week</h2>
            <ul class="divide-y divide-rule rounded-xl border border-rule bg-card text-sm">
                @foreach ($last as $m)
                    <li class="px-4 py-2.5">
                        <b>{{ $m['title'] }}</b>:
                        @if ($m['leader'])
                            {{ config('stoop.stations.'.$m['leader']) }} won, {{ implode('–', array_values($m['stops'])) }}.
                        @else
                            a draw at {{ implode('–', array_values($m['stops'])) }}.
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>

        @include('partials.line-cup')
    </div>
</x-layouts::stoop>
