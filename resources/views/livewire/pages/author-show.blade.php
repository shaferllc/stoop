<div class="space-y-3">
    <section class="flex items-start gap-4 rounded-xl border border-rule bg-card p-5">
        @if ($author->photo_url)
            <img src="{{ $author->photo_url }}" alt="" class="size-16 shrink-0 rounded-full bg-chip object-cover" referrerpolicy="no-referrer">
        @endif
        <div class="min-w-0 flex-1">
            <div class="text-[11.5px] font-semibold uppercase tracking-wide text-flag">{{ $author->title ?: 'Writer' }} · {{ config('stoop.magazine_name') }}</div>
            <h1 class="font-display text-4xl font-bold leading-tight">{{ $author->name }}</h1>
            <div class="mt-1 flex flex-wrap items-center gap-x-3 text-sm text-mute">
                <span><b class="tabular-nums text-ink">{{ number_format($author->followers_count) }}</b> {{ str('follower')->plural($author->followers_count) }}</span>
                @if ($author->user)
                    <a href="{{ route('users.show', $author->user) }}" wire:navigate class="underline">{{ '@'.$author->user->username }} on Stoop</a>
                @endif
            </div>
        </div>
        <button type="button" wire:click="toggleFollow" aria-pressed="{{ $following ? 'true' : 'false' }}" @class(['shrink-0 rounded-lg px-3 py-1.5 text-sm font-semibold', 'border border-rule' => $following, 'bg-flag text-white' => ! $following])>{{ $following ? 'Following' : 'Follow' }}</button>
    </section>

    <h2 class="pt-1 text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">Stories</h2>
    <ul class="divide-y divide-rule rounded-xl border border-rule bg-card">
        @forelse ($articles as $article)
            <li class="px-4 py-3">
                <a href="{{ $article->url }}" target="_blank" rel="noopener" class="font-display text-[17px] font-bold leading-snug hover:underline">{{ $article->title }}</a>
                <div class="mt-0.5 flex flex-wrap gap-x-2 text-[12.5px] text-mute">
                    @if ($article->published_at)<span>{{ \Illuminate\Support\Carbon::parse($article->published_at)->format('M j, Y') }}</span>@endif
                    @if ($article->dispatch_id)
                        <a href="{{ route('dispatches.show', $article->dispatch_id) }}" wire:navigate class="hover:text-ink">{{ $article->discussion ? $article->discussion.' '.str('neighbor')->plural($article->discussion).' talking' : 'Start the conversation' }}</a>
                    @elseif ($article->discussion)
                        <span>{{ $article->discussion }} {{ str('neighbor')->plural($article->discussion) }} talking</span>
                    @endif
                </div>
            </li>
        @empty
            <li><x-empty title="No stories yet" class="border-0">New stories show up here as they’re published.</x-empty></li>
        @endforelse
    </ul>
</div>
