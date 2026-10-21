<x-layouts::stoop title="Snow map">
    <div class="space-y-3">
        <h1 class="font-display text-3xl font-bold">❄ Snow map</h1>
        <p class="text-mute">The latest report from each stop. {{ $snowDay ? 'Add yours: pick “Snow report” in the composer.' : 'Snow Day mode is off right now.' }}</p>
        <div class="overflow-x-auto rounded-xl border border-rule bg-card">
            <table class="w-full text-sm">
                <thead class="text-left text-[11px] uppercase tracking-wide text-mute"><tr><th class="px-4 py-2">Stop</th><th class="px-4 py-2">Snow</th><th class="px-4 py-2">Plowed?</th><th class="px-4 py-2">Reported</th></tr></thead>
                <tbody class="divide-y divide-rule">
                    @forelse ($reports as $r)
                        <tr>
                            <td class="px-4 py-2"><a href="{{ route('dispatches.show', $r) }}"><x-station :code="$r->stop" :line="\App\Support\Stations::linesAt($r->stop)[0] ?? null" /></a></td>
                            <td class="px-4 py-2 font-semibold tabular-nums">{{ rtrim(rtrim(number_format($r->details['snow_inches'], 1), '0'), '.') }}″</td>
                            <td class="px-4 py-2">{{ ['yes' => 'Yes', 'partly' => 'Partly', 'no' => 'Not yet'][$r->details['plowed']] ?? '' }}</td>
                            <td class="px-4 py-2 text-mute">{{ $r->created_at->diffForHumans() }} by {{ '@'.$r->user->username }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="p-6 text-center text-mute">No reports in the last two days.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-layouts::stoop>
