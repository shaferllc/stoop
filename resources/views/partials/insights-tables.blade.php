{{-- The reader insights tables, shared by the admin page and the printable view. $report is already k-anonymous. --}}
@php($sections = [
    'stations' => ['By station', 'Stations stand in for neighborhoods. Sentiment runs from −100 to 100.'],
    'topics' => ['By topic (line)', null],
    'places' => ['Top Been here places', 'Distinct people who checked in.'],
    'keywords' => ['Rising keywords', 'Words used by at least '.\App\Support\ReaderInsights::K.' people, compared with the period before.'],
    'participation' => ['Games and quizzes', 'Distinct people who played.'],
])
@php($labels = ['station' => 'Station', 'topic' => 'Topic', 'posts' => 'Posts', 'people' => 'People', 'top_topics' => 'Top topics', 'sentiment' => 'Sentiment', 'place' => 'Place', 'keyword' => 'Keyword', 'before' => 'Period before', 'activity' => 'Activity'])

<dl class="mt-3 grid grid-cols-3 gap-2 text-center">
    @foreach (['posts' => 'Posts', 'people' => 'Active people', 'sentiment' => 'Sentiment'] as $key => $label)
        <div class="rounded-xl border border-rule bg-card p-3"><dt class="text-[11px] uppercase tracking-wide text-mute">{{ $label }}</dt><dd class="font-display text-2xl font-bold tabular-nums">{{ is_numeric($report['totals'][$key]) ? number_format($report['totals'][$key]) : $report['totals'][$key] }}</dd></div>
    @endforeach
</dl>

@foreach ($sections as $key => [$title, $note])
    <section class="mt-4 break-inside-avoid">
        <h2 class="font-display text-lg font-bold">{{ $title }}</h2>
        @if ($note)<p class="text-[12.5px] text-mute">{{ $note }}</p>@endif
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
                        <tr><td class="p-4 text-center text-mute">Nothing in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endforeach

<p class="mt-4 text-[12px] text-mute">Aggregate numbers only. Any number based on fewer than {{ \App\Support\ReaderInsights::K }} distinct people shows as “{{ \App\Support\ReaderInsights::SUPPRESSED }}”. No usernames, posts or emails; bots, staff and house accounts are left out.</p>
