<x-layouts::stoop :title="$issue->name.' issue'">
    <article class="rounded-xl border border-rule bg-card p-5">
        <div class="text-[10.5px] font-semibold uppercase tracking-[0.13em] text-mute">{{ config('stoop.magazine_name') }}</div>
        <h1 class="font-display text-3xl font-bold">The {{ $issue->name }} issue</h1>
        @unless ($issue->published_at)
            <p class="mt-1 text-sm font-semibold text-flag">Not published yet. Only staff can see this page.</p>
        @endunless

        <div class="mt-4 grid gap-5 sm:grid-cols-[minmax(0,14rem)_1fr]">
            @if ($issue->cover_url)
                <img src="{{ $issue->cover_url }}" alt="Cover of the {{ $issue->name }} issue" referrerpolicy="no-referrer" loading="lazy" class="w-full rounded-lg border border-rule">
            @endif
            <ol class="list-decimal space-y-2 pl-5">
                @foreach ($issue->stories as $story)
                    <li><a href="{{ $story['url'] }}" rel="noopener" class="font-semibold hover:underline">{{ $story['title'] ?: $story['url'] }}</a></li>
                @endforeach
            </ol>
        </div>

        @if ($issue->dispatch_id)
            <a href="{{ route('dispatches.show', $issue->dispatch_id) }}" wire:navigate class="mt-5 inline-block rounded-lg bg-flag px-4 py-2 text-sm font-semibold text-white">Talk about this issue on Stoop</a>
        @endif
    </article>
</x-layouts::stoop>
