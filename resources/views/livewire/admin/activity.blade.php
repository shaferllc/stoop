<div wire:poll.15s>
    <h1 class="mb-2 font-display text-3xl font-bold">Activity</h1>
    @include('partials.admin-nav')

    <div class="grid gap-3 lg:grid-cols-3">
        @foreach (['posts' => ['Posts a day', 'bg-ink'], 'removals' => ['Removals a day', 'bg-flag'], 'signups' => ['Sign-ups a day', 'bg-mute']] as $key => [$label, $color])
            @php($max = max(1, max(array_column($days, $key))))
            <section class="rounded-xl border border-rule bg-card p-4 text-sm">
                <h2 class="flex justify-between font-semibold">{{ $label }} <span class="font-normal text-mute">last 30 days · {{ number_format(array_sum(array_column($days, $key))) }}</span></h2>
                <div class="mt-3 flex h-28 items-end gap-px" role="img" aria-label="{{ $label }}, last 30 days">
                    @foreach ($days as $d)
                        <div class="{{ $color }} min-h-px flex-1 rounded-t-sm" style="height: {{ round($d[$key] / $max * 100) }}%" title="{{ \Illuminate\Support\Carbon::parse($d['day'])->format('M j') }}: {{ $d[$key] }}"></div>
                    @endforeach
                </div>
                <div class="mt-1 flex justify-between text-[11px] text-mute"><span>{{ \Illuminate\Support\Carbon::parse($days[0]['day'])->format('M j') }}</span><span>today</span></div>
            </section>
        @endforeach
    </div>

    <h2 class="mt-6 font-display text-xl font-bold">Live</h2>
    <ul class="mt-2 divide-y divide-rule rounded-xl border border-rule bg-card text-sm">
        @forelse ($feed as $item)
            <li class="flex items-baseline gap-2 px-4 py-2">
                <span @class(['w-16 shrink-0 rounded px-1.5 text-center text-[10px] font-bold uppercase', 'bg-chip' => $item['kind'] === 'post', 'bg-ink text-paper' => $item['kind'] === 'signup', 'border border-flag text-flag' => $item['kind'] === 'report', 'bg-flag text-white' => $item['kind'] === 'removal'])>{{ ['post' => 'Post', 'signup' => 'Sign-up', 'report' => 'Report', 'removal' => 'Removed'][$item['kind']] }}</span>
                @if ($item['username'])<b>{{ '@'.$item['username'] }}</b>@endif
                <a href="{{ $item['url'] }}" class="min-w-0 flex-1 truncate hover:underline">{{ $item['text'] }}</a>
                <span class="shrink-0 text-[12px] text-mute">{{ \Illuminate\Support\Carbon::parse($item['at'])->diffForHumans(null, true) }}</span>
            </li>
        @empty
            <li class="p-6 text-center text-mute">Nothing yet.</li>
        @endforelse
    </ul>
</div>
