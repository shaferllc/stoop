<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <meta name="robots" content="noindex">
        <title>{{ $report['area'] }} · {{ $report['month'] }} · Stoop area report</title>
        @vite(['resources/css/app.css'])
    </head>
    <body class="mx-auto max-w-4xl bg-paper p-6 font-sans text-[14px] text-ink">
        @php($labels = ['month' => 'Month', 'posts' => 'Posts', 'people' => 'People', 'topic' => 'Topic', 'category' => 'Kind of place', 'checkins' => 'Check-ins', 'events' => 'Happenings posted', 'hosts' => 'Hosts', 'rsvps' => 'RSVPs (going or interested)', 'people_rsvping' => 'People who RSVP’d', 'sales' => 'Sales', 'median_price' => 'Median price ($)', 'city_median_price' => 'City median ($)', 'measure' => 'Measure', 'area' => 'This area', 'city' => 'Whole city'])
        <div class="flex items-baseline justify-between gap-4">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-mute">Stoop · DC, block by block</p>
                <h1 class="font-display text-3xl font-bold">{{ $report['area'] }}</h1>
                <p class="text-mute">Neighborhood report, {{ $report['month'] }} · around {{ $report['stations'] }}</p>
            </div>
            <button type="button" onclick="window.print()" class="rounded-lg border border-rule px-3 py-1.5 print:hidden">Print or save as PDF</button>
        </div>
        @if ($report['verify'] && ($staff ?? false))
            <p class="mt-2 rounded-lg bg-chip px-3 py-2 text-sm print:hidden"><b>VERIFY:</b> this area’s stations were mapped by hand and haven’t been checked against the district’s boundaries yet.</p>
        @endif

        <dl class="mt-3 grid grid-cols-3 gap-2 text-center">
            @foreach (['posts' => 'Posts', 'people' => 'Active neighbors', 'sentiment' => 'Sentiment'] as $key => $label)
                <div class="rounded-xl border border-rule bg-card p-3"><dt class="text-[11px] uppercase tracking-wide text-mute">{{ $label }}</dt><dd class="font-display text-2xl font-bold tabular-nums">{{ is_int($report['totals'][$key]) ? number_format($report['totals'][$key]) : $report['totals'][$key] }}</dd></div>
            @endforeach
        </dl>

        @foreach (\App\Support\AreaReports::SECTIONS as $key => $title)
            <section class="mt-4 break-inside-avoid">
                <h2 class="font-display text-lg font-bold">{{ $title }}</h2>
                <div class="mt-1.5 overflow-x-auto rounded-xl border border-rule bg-card">
                    <table class="w-full text-sm">
                        @if ($report[$key])
                            <thead class="text-left text-[11px] uppercase tracking-wide text-mute"><tr>
                                @foreach (array_keys($report[$key][0]) as $column)<th class="px-4 py-2">{{ $labels[$column] ?? $column }}</th>@endforeach
                            </tr></thead>
                        @endif
                        <tbody class="divide-y divide-rule">
                            @forelse ($report[$key] as $row)
                                <tr>@foreach ($row as $value)<td @class(['px-4 py-1.5', 'tabular-nums' => is_numeric($value), 'text-mute' => $value === \App\Support\ReaderInsights::SUPPRESSED])>{{ is_int($value) ? number_format($value) : $value }}</td>@endforeach</tr>
                            @empty
                                <tr><td class="px-4 py-3 text-mute">Not enough activity to show yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endforeach

        <p class="mt-6 text-[12px] text-mute">Aggregate, anonymous numbers from Stoop neighbors who posted near these stations, leaving out bots, staff and house accounts. Any number drawn from fewer than {{ \App\Support\ReaderInsights::K }} people (or {{ \App\Support\ReaderInsights::K }} home sales) shows as {{ \App\Support\ReaderInsights::SUPPRESSED }}. Sentiment runs from −100 to 100 and is based on simple word lists. Home sales are from DC’s public property records.</p>
    </body>
</html>
