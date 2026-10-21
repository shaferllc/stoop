<div>
    <h1 class="mb-2 font-display text-3xl font-bold">Call-outs</h1>
    @include('partials.admin-nav')

    <p class="mb-3 text-sm text-mute">People replying to a callout can tick “The newsroom may quote this reply in a story.” Only those replies show here and go in the export. Write callouts from the composer (pick Callout).</p>

    <div class="grid gap-3 lg:grid-cols-[18rem_1fr]">
        <ul class="divide-y divide-rule self-start rounded-xl border border-rule bg-card text-sm">
            @forelse ($callouts as $c)
                <li wire:key="c-{{ $c->id }}"><button type="button" wire:click="select({{ $c->id }})" @class(['block w-full px-3 py-2 text-left hover:bg-chip', 'bg-chip' => $callout?->id === $c->id])>
                    {{ str($c->body)->limit(80) }}
                    <span class="block text-[12.5px] text-mute">{{ $c->quotable_count }} quotable of {{ $c->replies_count }} replies · {{ $c->created_at->diffForHumans() }}</span>
                </button></li>
            @empty
                <li class="px-3 py-2 text-mute">No callouts yet.</li>
            @endforelse
        </ul>

        @if ($callout)
            <section class="rounded-xl border border-rule bg-card p-4 text-sm">
                <p><a href="{{ route('dispatches.show', $callout) }}" class="font-semibold hover:underline">{{ $callout->body }}</a></p>

                <form wire:submit="saveStory" class="mt-3 grid gap-2 sm:grid-cols-2">
                    <label>Article URL<input wire:model="storyUrl" type="url" maxlength="500" placeholder="https://" class="mt-0.5 block w-full rounded-md border border-rule bg-paper px-2 py-1">@error('storyUrl')<span class="text-flag">{{ $message }}</span>@enderror</label>
                    <label>Working title<input wire:model="storyTitle" maxlength="160" class="mt-0.5 block w-full rounded-md border border-rule bg-paper px-2 py-1">@error('storyTitle')<span class="text-flag">{{ $message }}</span>@enderror</label>
                    <p class="text-[12.5px] text-mute sm:col-span-2">For the newsroom only; readers don’t see it.</p>
                    <div class="flex gap-2 sm:col-span-2">
                        <button class="rounded-lg border border-rule px-3 py-1.5">Save story</button>
                        @if ($quotes->isNotEmpty())
                            <button type="button" wire:click="export" class="rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Export quotes (CSV)</button>
                        @endif
                    </div>
                </form>

                <h2 class="mt-4 font-semibold">Quotable replies ({{ $quotes->count() }})</h2>
                <ul class="mt-2 space-y-2">
                    @forelse ($quotes as $q)
                        <li wire:key="q-{{ $q->id }}" class="rounded-lg bg-chip px-3 py-2" x-data="{ copied: false }">
                            <p x-ref="quote">“{{ $q->body }}” — {{ $q->user->name }} ({{ '@'.$q->user->username }}) on Stoop</p>
                            <div class="mt-1 flex gap-3 text-[12.5px] text-mute">
                                <button type="button" x-on:click="navigator.clipboard.writeText($refs.quote.innerText); copied = true" x-text="copied ? 'Copied' : 'Copy'" class="underline"></button>
                                <a href="{{ route('dispatches.show', $q) }}" class="underline">Open reply</a>
                                <span>{{ $q->created_at->toFormattedDateString() }}</span>
                            </div>
                        </li>
                    @empty
                        <li class="text-mute">Nobody has agreed to be quoted yet.</li>
                    @endforelse
                </ul>
            </section>
        @endif
    </div>
</div>
