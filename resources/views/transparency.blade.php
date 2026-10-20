<x-layouts::stoop title="Transparency report">
    <article class="rounded-xl border border-rule bg-card p-6">
        <h1 class="font-display text-3xl font-bold">Transparency report</h1>
        <p class="mt-1 text-mute">How Stoop was moderated in {{ $month->format('F Y') }}. Numbers update as the month goes.</p>

        <form method="GET" class="mt-3 flex items-center gap-2 text-sm">
            <label for="month" class="text-mute">Month</label>
            <input id="month" type="month" name="month" value="{{ $month->format('Y-m') }}" max="{{ now()->format('Y-m') }}" class="rounded-md border border-rule bg-paper px-2 py-1">
            <button class="rounded-md border border-rule px-2.5 py-1">Show</button>
        </form>

        <dl class="mt-5 grid gap-x-8 sm:grid-cols-2">
            @foreach ($stats as $label => $value)
                <div class="flex justify-between border-b border-rule py-2"><dt class="text-mute">{{ $label }}</dt><dd class="font-semibold tabular-nums">{{ number_format($value) }}</dd></div>
            @endforeach
        </dl>

        <h2 class="mt-6 font-display text-xl font-bold">Reports received, by reason</h2>
        <dl class="mt-2 grid gap-x-8 sm:grid-cols-2">
            @foreach (config('stoop.report_reasons') as $key => $label)
                <div class="flex justify-between border-b border-rule py-2"><dt class="text-mute">{{ $label }}</dt><dd class="font-semibold tabular-nums">{{ number_format($reports[$key] ?? 0) }}</dd></div>
            @endforeach
        </dl>
    </article>
</x-layouts::stoop>
