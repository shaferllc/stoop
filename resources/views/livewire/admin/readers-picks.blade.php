<div>
    <h1 class="mb-2 font-display text-3xl font-bold">Readers’ picks</h1>
    @include('partials.admin-nav')

    <p class="mb-3 text-sm text-mute">Been here check-ins (distinct neighbors), Been here posts and average sentiment for each place on a list. Sentiment is a plain count of positive and negative words, from −1 to 1.</p>

    <div class="mb-3 flex flex-wrap items-center gap-2 text-sm">
        <select wire:model.live="list" aria-label="List" class="rounded-md border border-rule bg-paper px-2 py-1">
            @forelse ($lists as $l)<option>{{ $l }}</option>@empty<option value="">No lists imported yet</option>@endforelse
        </select>
        <select wire:model.live="days" aria-label="Window" class="rounded-md border border-rule bg-paper px-2 py-1">
            <option value="30">Last 30 days</option><option value="90">Last 90 days</option><option value="365">Last year</option>
        </select>
        @if ($list !== '')<a href="{{ route('admin.readers-picks.csv', ['list' => $list, 'days' => $days]) }}" class="ml-auto rounded-lg border border-rule px-3 py-1 font-semibold">Download CSV</a>@endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-rule bg-card">
        <table class="w-full text-left text-sm">
            <thead class="text-[11px] uppercase tracking-wide text-mute">
                <tr><th class="px-3 py-2">Place</th><th class="px-3 py-2 text-right">Check-ins</th><th class="px-3 py-2 text-right">Posts</th><th class="px-3 py-2 text-right">Sentiment</th><th class="px-3 py-2">Recent Been here</th></tr>
            </thead>
            <tbody class="divide-y divide-rule">
                @forelse ($rows as $row)
                    <tr wire:key="pick-{{ $row['place']->id }}" class="align-top">
                        <td class="px-3 py-2"><a href="{{ route('places.show', $row['place']) }}" class="font-semibold underline">{{ $row['place']->name }}</a>@if ($row['place']->list_rank)<span class="text-mute"> · No. {{ $row['place']->list_rank }}</span>@endif</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $row['checkins'] }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $row['posts'] }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $row['sentiment'] === null ? '–' : number_format($row['sentiment'], 2) }}</td>
                        <td class="px-3 py-2 text-mute">
                            @foreach ($row['recent'] as $d)
                                <div><a href="{{ route('dispatches.show', $d) }}" class="text-ink">{{ '@'.$d->user?->username }}</a>: {{ str($d->body)->limit(100) }}</div>
                            @endforeach
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-3 py-4 text-mute">Import a list in Places first.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
