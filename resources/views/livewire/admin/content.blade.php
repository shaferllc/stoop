<div>
    <h1 class="mb-2 font-display text-3xl font-bold">Content</h1>
    @include('partials.admin-nav')

    <section class="rounded-xl border border-rule bg-card p-4 text-sm">
        <h2 class="font-semibold">Callouts</h2>
        <p class="mt-1 text-mute">A callout a day keeps the feed alive. Write one from the composer (pick Callout), and use Schedule to line up the week.</p>
        <a href="{{ route('home') }}#compose" class="mt-2 inline-block rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Write a callout</a>
        <ul class="mt-3 divide-y divide-rule">
            @forelse ($callouts as $d)
                <li class="py-2"><a href="{{ route('dispatches.show', $d) }}" wire:navigate class="hover:underline">{{ str($d->body)->limit(100) }}</a> <span class="text-mute">· {{ $d->replies_count }} replies · {{ $d->created_at->diffForHumans() }}{{ $d->sponsor ? ' · sponsored by '.$d->sponsor : '' }}</span></li>
            @empty
                <li class="py-2 text-mute">No callouts yet.</li>
            @endforelse
        </ul>
    </section>

    <section class="mt-3 rounded-xl border border-rule bg-card p-4 text-sm">
        <h2 class="font-semibold">Scheduled dispatches</h2>
        <ul class="mt-2 divide-y divide-rule">
            @forelse ($scheduled as $d)
                <li class="py-2"><b>{{ $d->publish_at->format('D M j, g:ia') }}</b> · {{ '@'.$d->user->username }} · {{ str($d->body)->limit(100) }}</li>
            @empty
                <li class="py-2 text-mute">Nothing scheduled.</li>
            @endforelse
        </ul>
    </section>
</div>
