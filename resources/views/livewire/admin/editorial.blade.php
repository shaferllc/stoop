<div>
    <h1 class="mb-2 font-display text-3xl font-bold">Editorial</h1>
    @include('partials.admin-nav')
    @php($tz = config('stoop.timezone'))
    @php($input = 'rounded-md border border-rule bg-paper px-2.5 py-1.5')

    <section class="rounded-xl border border-rule bg-card p-4 text-sm">
        <h2 class="font-semibold">Ask the editor</h2>
        <p class="text-mute">Posts a thread from you. Readers ask and upvote questions between the start and end; you answer on the thread’s page.</p>
        <form wire:submit="openAma" class="mt-2 grid gap-2 sm:grid-cols-[1fr_auto_auto_auto] sm:items-end">
            <input wire:model="amaTitle" maxlength="160" placeholder="Our food critic on the year’s best new restaurants" aria-label="Title" class="{{ $input }}">
            <label>Starts (DC time)<input type="datetime-local" wire:model="amaStarts" class="mt-0.5 block {{ $input }}"></label>
            <label>Ends<input type="datetime-local" wire:model="amaEnds" class="mt-0.5 block {{ $input }}"></label>
            <button class="rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Post thread</button>
        </form>
        @foreach (['amaTitle', 'amaStarts', 'amaEnds', 'body'] as $f) @error($f) <p class="mt-1 text-flag">{{ $message }}</p> @enderror @endforeach
        <ul class="mt-3 divide-y divide-rule">
            @foreach ($threads as $t)
                <li class="flex items-center gap-2 py-1.5">
                    <a href="{{ route('ama.show', $t) }}" wire:navigate class="font-semibold hover:underline">{{ $t->title }}</a>
                    <span class="text-mute">{{ '@'.$t->editor?->username }} · {{ $t->starts_at->timezone($tz)->format('M j, g:ia') }}–{{ $t->ends_at->timezone($tz)->format('M j, g:ia') }}{{ $t->isOpen() ? ' · open now' : '' }}</span>
                </li>
            @endforeach
        </ul>
    </section>

    <section class="mt-3 rounded-xl border border-rule bg-card p-4 text-sm">
        <h2 class="font-semibold">Best of Washington reader vote</h2>
        <p class="text-mute">One category per line: a name, a colon, then the nominated places by name or slug, separated by commas.</p>
        <form wire:submit="createBallot" class="mt-2 space-y-2">
            <div class="grid gap-2 sm:grid-cols-[1fr_auto_auto] sm:items-end">
                <input wire:model="ballotTitle" maxlength="160" placeholder="Best of Washington 2027" aria-label="Ballot title" class="{{ $input }}">
                <label>Opens (DC time)<input type="datetime-local" wire:model="ballotOpens" class="mt-0.5 block {{ $input }}"></label>
                <label>Closes<input type="datetime-local" wire:model="ballotCloses" class="mt-0.5 block {{ $input }}"></label>
            </div>
            <textarea wire:model="ballotCategories" rows="4" placeholder="Best half-smoke: Ben's Chili Bowl, Weenie Beenie&#10;Best bagel: call-your-mother, Bullfrog Bagels" aria-label="Categories" class="w-full font-mono text-[13px] {{ $input }}"></textarea>
            @foreach (['ballotTitle', 'ballotOpens', 'ballotCloses', 'ballotCategories'] as $f) @error($f) <p class="text-flag">{{ $message }}</p> @enderror @endforeach
            <button class="rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Create ballot</button>
        </form>
        <ul class="mt-3 divide-y divide-rule">
            @foreach ($ballots as $b)
                <li class="flex flex-wrap items-center gap-2 py-1.5">
                    <a href="{{ route('ballots.show', $b) }}" wire:navigate class="font-semibold hover:underline">{{ $b->title }}</a>
                    <span class="text-mute">{{ $b->opens_at->timezone($tz)->format('M j') }}–{{ $b->closes_at->timezone($tz)->format('M j, g:ia') }} · {{ $b->isOpen() ? 'open' : ($b->isClosed() ? 'closed' : 'not open yet') }}</span>
                    <span class="ml-auto flex gap-2">
                        @unless ($b->isClosed())
                            <button type="button" wire:click="closeBallot({{ $b->id }})" wire:confirm="Close voting now? Results go public." class="rounded-lg border border-rule px-2.5 py-0.5">Close now</button>
                        @endunless
                        <a href="{{ route('admin.ballots.csv', $b) }}" class="rounded-lg border border-rule px-2.5 py-0.5">Results CSV</a>
                    </span>
                </li>
            @endforeach
        </ul>
    </section>
</div>
