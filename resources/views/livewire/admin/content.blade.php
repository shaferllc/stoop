<div>
    <h1 class="mb-2 font-display text-3xl font-bold">Content</h1>
    @include('partials.admin-nav')

    <div class="mb-3 grid gap-3 lg:grid-cols-2">
        <section class="rounded-xl border border-rule bg-card p-4 text-sm">
            <h2 class="font-semibold">Magazine articles</h2>
            <p class="mt-1 text-mute">{{ $feed ? 'New articles from '.$feed.' post automatically every 15 minutes.' : 'Set STOOP_MAGAZINE_FEED in .env to the site’s RSS feed to post new articles automatically.' }}</p>
            <button type="button" wire:click="importNow" class="mt-2 rounded-lg border border-rule px-3 py-1.5">Check the feed now</button>
            <ul class="mt-3 divide-y divide-rule">
                @forelse ($articles as $a)
                    <li class="py-1.5">{{ $a->title }} @if ($a->dispatch_id)<a href="{{ route('dispatches.show', $a->dispatch_id) }}" class="text-mute underline">posted</a>@else<span class="text-flag">not posted</span>@endif</li>
                @empty
                    <li class="py-1.5 text-mute">Nothing imported yet.</li>
                @endforelse
            </ul>
        </section>

        <section class="rounded-xl border border-rule bg-card p-4 text-sm">
            <h2 class="font-semibold">Weekly email</h2>
            <p class="mt-1 text-mute">Goes out Mondays at 8am to the {{ number_format($newsletterReaders) }} {{ str('person')->plural($newsletterReaders) }} who turned it on.</p>
            <div class="mt-2 flex gap-2">
                <a href="{{ route('admin.newsletter.preview') }}" target="_blank" class="rounded-lg border border-rule px-3 py-1.5">Preview this week’s</a>
                <button type="button" wire:click="sendTest" class="rounded-lg border border-rule px-3 py-1.5">Send me a test</button>
            </div>
        </section>
    </div>

    <section class="mb-3 rounded-xl border border-rule bg-card p-4 text-sm">
        <h2 class="font-semibold">Passport stamps</h2>
        <p class="mt-1 text-mute">People who earned a stamp, for shout-outs in the magazine.</p>
        <ul class="mt-2 divide-y divide-rule">
            @forelse ($stamps as $stamp)
                <li class="py-1.5"><a href="{{ route('users.show', $stamp['user']) }}" class="font-semibold">{{ $stamp['user']->name }}</a> {{ '@'.$stamp['user']->username }} · {{ $stamp['list'] }} · <span class="text-mute">{{ \Illuminate\Support\Carbon::parse($stamp['stamped_at'])->toFormattedDateString() }}</span></li>
            @empty
                <li class="py-1.5 text-mute">No stamps yet.</li>
            @endforelse
        </ul>
    </section>

    <section class="rounded-xl border border-rule bg-card p-4 text-sm">
        <h2 class="font-semibold">Callouts</h2>
        <p class="mt-1 text-mute">A callout a day keeps the feed alive. Write one from the composer (pick Callout), and use Schedule to line up the week.</p>
        <button type="button" x-data x-on:click="$dispatch('open-composer')" class="mt-2 rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Write a callout</button>
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
