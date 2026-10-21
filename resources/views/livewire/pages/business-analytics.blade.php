<div class="space-y-3">
    <section class="rounded-xl border border-rule bg-card p-5">
        <a href="{{ route('business.edit') }}" wire:navigate class="text-sm text-mute underline">Business settings</a>
        <h1 class="font-display text-3xl font-bold">{{ $place->name }}: the last 30 days</h1>

        @if (! $isBusiness)
            <p class="mt-2 text-sm">Analytics come with the Business plan. <a href="{{ route('business.edit') }}" wire:navigate class="underline">See plans</a></p>
        @else
            @php($totals = ['views' => 'Page views', 'deal_views' => 'Deal views', 'deal_clicks' => 'Deal clicks', 'checkins' => 'Check-ins', 'been' => 'Been here posts'])
            <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-5">
                @foreach ($totals as $key => $label)
                    <div class="rounded-lg bg-chip p-3"><dt class="text-[11px] uppercase tracking-wide text-mute">{{ $label }}</dt><dd class="font-display text-2xl font-bold tabular-nums">{{ number_format($rows->sum($key)) }}</dd></div>
                @endforeach
            </dl>
            <p class="mt-2 text-[12.5px] text-mute">Views and clicks count once per person per day. Check-ins are neighbors tapping Been here on posts about {{ $place->name }}.</p>

            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-[11px] uppercase tracking-wide text-mute"><tr><th class="py-1">Day</th>@foreach ($totals as $label)<th class="text-right">{{ $label }}</th>@endforeach</tr></thead>
                    <tbody class="divide-y divide-rule">
                        @foreach ($rows->reverse() as $row)
                            <tr><td class="py-1">{{ \Illuminate\Support\Carbon::parse($row->day)->format('D M j') }}</td>@foreach (array_keys($totals) as $key)<td class="text-right tabular-nums">{{ $row->$key }}</td>@endforeach</tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($promotions->isNotEmpty())
        <section class="rounded-xl border border-rule bg-card p-5">
            <h2 class="font-display text-xl font-bold">Promotions</h2>
            @include('partials.promotion-rows')
        </section>
    @endif
</div>
