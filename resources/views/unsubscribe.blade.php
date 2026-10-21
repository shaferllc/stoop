<x-layouts::stoop title="Weekly email">
    <div class="rounded-xl border border-rule bg-card p-6">
        @if ($done)
            <h1 class="font-display text-2xl font-bold">You’re unsubscribed</h1>
            <p class="mt-1 text-mute">No more weekly emails. You can turn them back on in Settings.</p>
        @else
            <h1 class="font-display text-2xl font-bold">Stop the weekly email?</h1>
            <form method="POST" action="{{ $action }}" class="mt-3"><button class="rounded-lg bg-flag px-4 py-2 text-sm font-semibold text-white">Unsubscribe</button></form>
        @endif
    </div>
</x-layouts::stoop>
