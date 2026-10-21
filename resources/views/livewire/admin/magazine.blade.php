<div>
    <h1 class="mb-2 font-display text-3xl font-bold">Magazine</h1>
    @include('partials.admin-nav')

    <section class="rounded-xl border border-rule bg-card p-4 text-sm">
        <h2 class="font-semibold">Reader poll for an article</h2>
        <p class="text-mute">Posts as {{ '@'.config('stoop.magazine_account') }}. Results show on Stoop and live on the article.</p>
        <form wire:submit="createPoll" class="mt-2 space-y-2">
            <input wire:model="pollArticle" type="url" maxlength="500" placeholder="{{ (config('stoop.magazine_origins')[0] ?? 'https://').'/…' }}" aria-label="Article URL" class="w-full rounded-md border border-rule bg-paper px-2.5 py-1.5">
            <input wire:model="pollQuestion" maxlength="{{ config('stoop.max_length') }}" placeholder="Question" aria-label="Question" class="w-full rounded-md border border-rule bg-paper px-2.5 py-1.5">
            <div class="grid gap-2 sm:grid-cols-2">
                @foreach ($pollOptions as $i => $o)
                    <input wire:model="pollOptions.{{ $i }}" maxlength="50" placeholder="Option {{ $i + 1 }}{{ $i > 1 ? ' (optional)' : '' }}" aria-label="Option {{ $i + 1 }}" class="rounded-md border border-rule bg-paper px-2.5 py-1.5">
                @endforeach
            </div>
            <div class="flex flex-wrap gap-2">
                <select wire:model="pollLine" aria-label="Line" class="rounded-md border border-rule bg-paper px-2 py-1.5">
                    @foreach (config('stoop.lines') as $key => $line)<option value="{{ $key }}">{{ $line['name'] }}</option>@endforeach
                </select>
                <select wire:model="pollHours" aria-label="Open for" class="rounded-md border border-rule bg-paper px-2 py-1.5">
                    @foreach ([1 => '1 hour', 24 => '1 day', 72 => '3 days', 168 => '7 days'] as $h => $label)<option value="{{ $h }}">{{ $label }}</option>@endforeach
                </select>
                <button class="rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Post poll</button>
            </div>
            @foreach (['pollArticle', 'body', 'options', 'options.*', 'line', 'pollHours'] as $f) @error($f) <p class="text-flag">{{ $message }}</p> @enderror @endforeach
        </form>
        @if ($pollId)
            <p class="mt-3 text-mute">Embed code for the article:</p>
            <pre class="mt-1 overflow-x-auto rounded-md bg-chip p-2 text-[12px]">{{ '<div data-stoop-poll="'.$pollId.'"></div>' }}
{{ '<script src="'.route('embed').'" async></script>' }}</pre>
        @endif
    </section>

    <section class="mt-3 rounded-xl border border-rule bg-card text-sm">
        <h2 class="px-4 pt-3 font-semibold">Writers</h2>
        <p class="px-4 text-mute">Made from article bylines. Add a title and photo, or connect the writer’s own Stoop account.</p>
        <ul class="mt-2 divide-y divide-rule">
            @forelse ($authors as $author)
                <li class="px-4 py-2.5">
                    @if ($editing === $author->id)
                        <form wire:submit="save" class="grid gap-2 sm:grid-cols-2">
                            <input wire:model="name" maxlength="120" aria-label="Name" class="rounded-md border border-rule bg-paper px-2.5 py-1.5">
                            <input wire:model="title" maxlength="80" placeholder="Title, like Food critic" aria-label="Title" class="rounded-md border border-rule bg-paper px-2.5 py-1.5">
                            <input wire:model="photoUrl" type="url" maxlength="500" placeholder="Photo URL (https)" aria-label="Photo URL" class="rounded-md border border-rule bg-paper px-2.5 py-1.5">
                            <input wire:model="username" maxlength="60" placeholder="Stoop username (optional)" aria-label="Stoop username" class="rounded-md border border-rule bg-paper px-2.5 py-1.5">
                            @foreach (['name', 'title', 'photoUrl', 'username'] as $f) @error($f) <p class="text-flag sm:col-span-2">{{ $message }}</p> @enderror @endforeach
                            <div class="flex gap-2 sm:col-span-2"><button class="rounded-lg bg-flag px-3 py-1 font-semibold text-white">Save</button><button type="button" wire:click="$set('editing', null)" class="rounded-lg border border-rule px-3 py-1">Cancel</button></div>
                        </form>
                    @else
                        <div class="flex items-center gap-3">
                            <div class="min-w-0 flex-1">
                                <a href="{{ $author->url() }}" wire:navigate class="font-semibold hover:underline">{{ $author->name }}</a>
                                @if ($author->title)<span class="text-mute">· {{ $author->title }}</span>@endif
                                <div class="text-[12.5px] text-mute">{{ $author->followers_count }} {{ str('follower')->plural($author->followers_count) }}@if ($author->user) · {{ '@'.$author->user->username }}@endif · <code>data-stoop-byline="{{ $author->slug }}"</code></div>
                            </div>
                            <button type="button" wire:click="edit({{ $author->id }})" class="rounded-lg border border-rule px-2.5 py-0.5">Edit</button>
                        </div>
                    @endif
                </li>
            @empty
                <li class="px-4 py-3 text-mute">Writers appear here as articles with bylines are imported.</li>
            @endforelse
        </ul>
    </section>

    <section class="mt-3 rounded-xl border border-rule bg-card text-sm">
        <h2 class="px-4 pt-3 font-semibold">Quote requests</h2>
        <p class="px-4 text-mute">Ask from the … menu on any dispatch. Authors have {{ \App\Models\QuoteRequest::DAYS }} days to answer.</p>
        <ul class="mt-2 divide-y divide-rule">
            @forelse ($requests as $r)
                <li class="px-4 py-2.5">
                    <div class="flex flex-wrap items-center gap-x-2">
                        <span @class(['rounded px-1 text-[10px] font-bold uppercase', 'bg-[#0b7a33] text-white' => $r->state() === 'allowed', 'bg-chip text-mute' => $r->state() !== 'allowed'])>{{ $r->state() }}</span>
                        @if ($r->dispatch?->user)<a href="{{ route('dispatches.show', $r->dispatch) }}" wire:navigate class="font-semibold">{{ '@'.$r->dispatch->user->username }}</a>@endif
                        <span class="text-mute">asked by {{ $r->editor ? '@'.$r->editor->username : 'a former editor' }} · {{ $r->created_at->toFormattedDateString() }}</span>
                    </div>
                    @if ($r->dispatch)<p class="mt-0.5 text-mute">{{ str($r->dispatch->body)->limit(140) }}</p>@endif
                    @if ($r->state() === 'allowed')
                        <pre class="mt-1 overflow-x-auto rounded-md bg-chip p-2 text-[12px]">{{ '<div data-stoop-quote="'.$r->id.'"></div>' }}</pre>
                    @endif
                </li>
            @empty
                <li class="px-4 py-3 text-mute">No requests yet.</li>
            @endforelse
        </ul>
    </section>
</div>
