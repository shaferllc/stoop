<div>
    <h1 class="mb-2 font-display text-3xl font-bold">Radar</h1>
    @include('partials.admin-nav')

    <p class="mb-3 text-sm text-mute">
        What’s growing: the last 7 days against the weekly average of the 4 weeks before. Trends need at least {{ \App\Support\Radar::MIN_VOLUME }} posts and replies this week; “new” means there was too little before for a percentage.
        Updated {{ \Illuminate\Support\Carbon::parse($radar['generated_at'])->diffForHumans() }}. <button type="button" wire:click="refresh" class="underline">Refresh</button>
        @unless ($aiOn) · Story ideas: <span class="font-semibold">Needs ANTHROPIC_API_KEY</span> @endunless
    </p>

    @foreach (['lines' => 'Topics', 'stations' => 'Stations', 'keywords' => 'Rising words', 'clusters' => 'Ask DC clusters'] as $group => $heading)
        <section class="mb-3 rounded-xl border border-rule bg-card p-4 text-sm">
            <h2 class="font-semibold">{{ $heading }}</h2>
            @if ($group === 'clusters')<p class="mt-0.5 text-mute">{{ \App\Support\Radar::CLUSTER_MIN }}+ Ask DC questions this week naming the same place, station or name.</p>@endif
            <ul class="mt-2 divide-y divide-rule">
                @forelse ($radar[$group] as $t)
                    <li wire:key="t-{{ $t['key'] }}" class="py-2">
                        <div class="flex flex-wrap items-baseline gap-x-2">
                            <a href="{{ $t['url'] }}" class="font-semibold hover:underline">{{ $t['label'] }}</a>
                            @if ($group === 'clusters')
                                <span class="text-mute">{{ $t['current'] }} questions</span>
                                @foreach ($t['post_ids'] as $i => $id)
                                    <a href="{{ route('dispatches.show', $id) }}" class="text-mute underline">#{{ $i + 1 }}</a>
                                @endforeach
                            @else
                                <span class="font-semibold text-flag">{{ $t['change'] === null ? 'new' : '+'.$t['change'].'%' }}</span>
                                <span class="text-mute">{{ $t['current'] }} this week{{ isset($t['posts']) ? ' ('.$t['posts'].' posts, '.$t['replies'].' replies)' : '' }} vs {{ $t['average'] }} a week before</span>
                            @endif
                            @if ($aiOn)
                                <button type="button" wire:click="idea({{ json_encode($t['key']) }})" class="ml-auto text-[12.5px] text-mute underline">Story idea</button>
                            @endif
                        </div>
                        @isset($ideas[$t['key']])
                            <p class="mt-1 rounded-lg bg-chip px-3 py-2"><span class="text-[11px] font-semibold uppercase tracking-wide text-mute">Claude suggests</span><br>{{ $ideas[$t['key']] }}</p>
                        @endisset
                    </li>
                @empty
                    <li class="py-2 text-mute">Nothing unusual this week.</li>
                @endforelse
            </ul>
        </section>
    @endforeach
</div>
