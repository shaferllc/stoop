<div>
    <h1 class="mb-2 font-display text-3xl font-bold">Issues</h1>
    @include('partials.admin-nav')

    <div class="mb-3 grid gap-3 lg:grid-cols-2">
        <form wire:submit="create" class="rounded-xl border border-rule bg-card p-4 text-sm">
            <h2 class="font-semibold">New issue</h2>
            <p class="mt-1 text-mute">Publishing posts one {{ '@'.config('stoop.magazine_account') }} thread: the issue first, then each story as a reply with its link card. It also gets its own page.</p>
            <label class="mt-2 block">Name<input wire:model="name" maxlength="120" placeholder="November 2026" class="mt-0.5 block w-full rounded-md border border-rule bg-paper px-2 py-1"></label>
            @error('name') <p class="text-flag">{{ $message }}</p> @enderror
            <label class="mt-2 block">Cover image URL<input wire:model="coverUrl" type="url" maxlength="500" placeholder="https://" class="mt-0.5 block w-full rounded-md border border-rule bg-paper px-2 py-1"></label>
            @error('coverUrl') <p class="text-flag">{{ $message }}</p> @enderror
            <label class="mt-2 block">Story URLs, one per line<textarea wire:model="urls" rows="6" class="mt-0.5 block w-full rounded-md border border-rule bg-paper px-2 py-1 font-mono text-[12px]"></textarea></label>
            @error('urls') <p class="text-flag">{{ $message }}</p> @enderror
            <button class="mt-2 rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Save issue</button>
        </form>

        <section class="rounded-xl border border-rule bg-card p-4 text-sm">
            <h2 class="font-semibold">Imported articles</h2>
            <p class="mt-1 text-mute">Add one to the issue, or mark it corrected or updated: {{ '@'.config('stoop.magazine_account') }} replies in its thread with your note, and everyone who replied, saved or reacted is notified once.</p>
            <ul class="mt-2 divide-y divide-rule">
                @forelse ($articles as $a)
                    <li wire:key="a-{{ $a->id }}" class="py-2">
                        <div class="flex gap-2">
                            <a href="{{ route('dispatches.show', $a->dispatch_id) }}" class="min-w-0 flex-1 hover:underline">{{ $a->title }}</a>
                            <button type="button" wire:click="addArticle({{ $a->id }})" class="shrink-0 text-mute underline">Add to issue</button>
                        </div>
                        <form wire:submit="postUpdate({{ $a->id }})" class="mt-1 flex gap-2">
                            <input wire:model="notes.{{ $a->id }}" maxlength="400" placeholder="Correction or update note" aria-label="Update note for {{ $a->title }}" class="min-w-0 flex-1 rounded-md border border-rule bg-paper px-2 py-1">
                            <button class="rounded-lg border border-rule px-2.5 py-1">Corrected/Updated</button>
                        </form>
                        @error('notes.'.$a->id) <p class="text-flag">{{ $message }}</p> @enderror
                    </li>
                @empty
                    <li class="py-2 text-mute">No articles imported yet.</li>
                @endforelse
            </ul>
        </section>
    </div>

    <section class="mb-3 rounded-xl border border-rule bg-card p-4 text-sm">
        <h2 class="font-semibold">Issues</h2>
        <ul class="mt-2 divide-y divide-rule">
            @forelse ($issues as $issue)
                <li wire:key="i-{{ $issue->id }}" class="flex flex-wrap items-center gap-2 py-2">
                    <a href="{{ route('issues.show', $issue) }}" class="font-semibold hover:underline">{{ $issue->name }}</a>
                    <span class="text-mute">{{ count($issue->stories) }} {{ str('story')->plural(count($issue->stories)) }}</span>
                    @if ($issue->published_at)
                        <span class="text-mute">· published {{ $issue->published_at->diffForHumans() }}</span>
                        @if ($issue->dispatch_id)<a href="{{ route('dispatches.show', $issue->dispatch_id) }}" class="underline">thread</a>@endif
                    @else
                        <button type="button" wire:click="publish({{ $issue->id }})" wire:confirm="Post {{ $issue->name }} to Stoop now?" class="ml-auto rounded-lg bg-flag px-3 py-1 font-semibold text-white">Publish</button>
                        <button type="button" wire:click="delete({{ $issue->id }})" class="rounded-lg border border-rule px-3 py-1">Delete</button>
                    @endif
                </li>
            @empty
                <li class="py-2 text-mute">No issues yet.</li>
            @endforelse
        </ul>
    </section>

    <section class="rounded-xl border border-rule bg-card p-4 text-sm">
        <h2 class="font-semibold">Recent article updates</h2>
        <ul class="mt-2 divide-y divide-rule">
            @forelse ($updates as $u)
                <li class="py-1.5">{{ $u->title }}: {{ $u->note }} <span class="text-mute">· {{ $u->created_by ? 'editor' : 'feed' }} · {{ $u->notified }} notified · {{ \Illuminate\Support\Carbon::parse($u->created_at)->diffForHumans() }}</span></li>
            @empty
                <li class="py-1.5 text-mute">None yet.</li>
            @endforelse
        </ul>
    </section>
</div>
