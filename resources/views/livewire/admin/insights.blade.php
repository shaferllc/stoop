<div>
    <h1 class="mb-2 font-display text-3xl font-bold">Reader insights</h1>
    @include('partials.admin-nav')
    <p class="text-sm text-mute">Aggregate, anonymized numbers to share with advertisers.</p>

    <div class="mt-3 flex flex-wrap items-end gap-2 text-sm">
        <label>From<input type="date" wire:model.live="from" class="mt-0.5 block rounded-md border border-rule bg-paper px-2 py-1"></label>
        <label>To<input type="date" wire:model.live="to" class="mt-0.5 block rounded-md border border-rule bg-paper px-2 py-1"></label>
        <a href="{{ route('admin.insights.csv', ['from' => $from, 'to' => $to]) }}" class="rounded-lg border border-rule px-3 py-1.5">Download CSV</a>
        <a href="{{ route('admin.insights.print', ['from' => $from, 'to' => $to]) }}" target="_blank" class="rounded-lg border border-rule px-3 py-1.5">Printable view (PDF)</a>
    </div>

    <section class="mt-4 rounded-xl border border-rule bg-card p-4 text-sm">
        <h2 class="font-display text-lg font-bold">Executive summary</h2>
        @if (! $claude)
            <p class="text-mute">Needs ANTHROPIC_API_KEY.</p>
        @elseif ($summary)
            <p class="mt-1">{{ $summary }}</p>
            <p class="mt-1 text-[12px] text-mute">Written by Claude from the aggregate numbers below only. Check it before sending.</p>
        @else
            <button type="button" wire:click="summarize" wire:loading.attr="disabled" class="mt-1 rounded-lg border border-rule px-3 py-1.5">Write a summary with Claude</button>
        @endif
    </section>

    @include('partials.insights-tables', ['report' => $report])
</div>
