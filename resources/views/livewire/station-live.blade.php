<div wire:poll.30s.visible class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
    <div>
        <h2 class="text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">Next trains</h2>
        @if ($trains === null)
            <p class="mt-1 text-mute">Live arrivals show up here once a WMATA key is set.</p>
        @elseif ($trains === [])
            <p class="mt-1 text-mute">No trains predicted right now.</p>
        @else
            <ul class="mt-1 space-y-1">
                @foreach ($trains as $t)
                    <li class="flex items-center gap-2">
                        <span class="h-2 w-5 rounded-full" style="background: {{ $t['line'] ? config("stoop.lines.{$t['line']}.color") : 'var(--color-mute)' }}"></span>
                        <span class="flex-1">{{ $t['destination'] }}</span>
                        <b class="tabular-nums">{{ in_array($t['minutes'], ['ARR', 'BRD']) ? ($t['minutes'] === 'ARR' ? 'Arriving' : 'Boarding') : $t['minutes'].' min' }}</b>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
    <div>
        <h2 class="text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">Capital Bikeshare nearby</h2>
        @if ($bikes === null)
            <p class="mt-1 text-mute">Bikeshare info isn’t available right now.</p>
        @elseif ($bikes === [])
            <p class="mt-1 text-mute">No docks within a few blocks.</p>
        @else
            <ul class="mt-1 space-y-1">
                @foreach ($bikes as $b)
                    <li><span class="font-medium">{{ $b['name'] }}</span> <span class="text-mute">· {{ $b['meters'] }} m</span><br>
                        <span class="text-[13px]">{{ $b['bikes'] }} {{ str('bike')->plural($b['bikes']) }}{{ $b['ebikes'] ? ' ('.$b['ebikes'].' e-bikes)' : '' }} · {{ $b['docks'] }} open {{ str('dock')->plural($b['docks']) }}</span></li>
                @endforeach
            </ul>
        @endif
    </div>
    @if ($inDc)
        <p class="text-[13px] text-mute sm:col-span-2">
            Something broken nearby? <a href="https://311.dc.gov/" target="_blank" rel="noopener" class="underline">Report it to DC 311</a>.
            Neighborhood decisions happen at your <a href="https://anc.dc.gov/" target="_blank" rel="noopener" class="underline">Advisory Neighborhood Commission</a>; find yours and its meeting calendar there.
        </p>
    @endif
</div>
