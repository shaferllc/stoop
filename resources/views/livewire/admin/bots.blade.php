<div>
    <h1 class="mb-2 font-display text-3xl font-bold">House bots</h1>
    @include('partials.admin-nav')

    <h2 class="font-display text-xl font-bold">Recent answers</h2>
    <p class="text-[12px] text-mute">What {{ '@stoop' }} and {{ '@dc' }} said lately. {{ config('services.anthropic.key') ? '@stoop answers with Claude.' : '@stoop answers from the knowledge base below (Claude needs ANTHROPIC_API_KEY).' }}</p>
    <ul class="mt-2 divide-y divide-rule rounded-xl border border-rule bg-card text-sm">
        @forelse ($answers as $a)
            <li wire:key="answer-{{ $a->id }}" class="px-4 py-3">
                @if ($a->parent)
                    <p class="text-mute"><b>{{ '@'.($a->parent->user?->username ?? '?') }}</b> {{ str($a->parent->body)->limit(160) }}</p>
                @endif
                <p class="mt-1"><b>{{ '@'.$a->user->username }}</b> {{ $a->body }}</p>
                <div class="mt-1.5 flex flex-wrap items-center gap-2 text-[12px]">
                    <a href="{{ route('dispatches.show', $a) }}" class="text-mute underline">{{ $a->created_at->diffForHumans() }}</a>
                    @if (! empty($a->details['marked_wrong_at']))
                        <span class="rounded bg-flag px-1.5 font-bold text-white">WRONG</span>
                    @else
                        <button type="button" wire:click="markWrong({{ $a->id }})" class="rounded-lg border border-rule px-2 py-0.5">Mark wrong</button>
                    @endif
                    <button type="button" wire:click="$set('correcting', {{ $a->id }})" class="rounded-lg border border-rule px-2 py-0.5">Post a correction</button>
                </div>
                @if ($correcting === $a->id)
                    <form wire:submit="correct" class="mt-2 flex gap-2">
                        <input wire:model="correction" maxlength="{{ config('stoop.max_length') }}" placeholder="Correction: …" aria-label="Correction" class="min-w-0 flex-1 rounded-md border border-rule bg-paper px-2.5 py-1.5">
                        <button class="rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Reply as {{ '@'.$a->user->username }}</button>
                    </form>
                    @error('correction') <p class="text-flag">{{ $message }}</p> @enderror
                @endif
            </li>
        @empty
            <li class="p-6 text-center text-mute">The bots haven’t answered anything yet.</li>
        @endforelse
    </ul>

    <h2 class="mt-6 font-display text-xl font-bold">{{ '@stoop' }}’s knowledge base</h2>
    <form wire:submit="save" class="mt-2 rounded-xl border border-rule bg-card p-4 text-sm">
        <div class="flex gap-2">
            <input wire:model="section" maxlength="80" placeholder="Section, like Posting" aria-label="Section" list="kb-sections" class="w-48 rounded-md border border-rule bg-paper px-2.5 py-1.5">
            <datalist id="kb-sections">@foreach ($entries->keys() as $s)<option value="{{ $s }}">@endforeach</datalist>
            <input wire:model="body" maxlength="1000" placeholder="One fact @stoop can answer from" aria-label="Entry" class="min-w-0 flex-1 rounded-md border border-rule bg-paper px-2.5 py-1.5">
            <button class="rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">{{ $editing ? 'Save' : 'Add' }}</button>
            @if ($editing)<button type="button" wire:click="$set('editing', null)" class="rounded-lg border border-rule px-3 py-1.5">Cancel</button>@endif
        </div>
        @foreach (['section', 'body'] as $f) @error($f) <p class="text-flag">{{ $message }}</p> @enderror @endforeach
    </form>
    @foreach ($entries as $section => $rows)
        <h3 class="mt-4 font-semibold">{{ $section }}</h3>
        <ul class="mt-1 divide-y divide-rule rounded-xl border border-rule bg-card text-sm">
            @foreach ($rows as $e)
                <li wire:key="kb-{{ $e->id }}" class="flex items-start gap-2 px-4 py-2">
                    <p class="min-w-0 flex-1">{{ $e->body }}</p>
                    <button type="button" wire:click="edit({{ $e->id }})" class="rounded-lg border border-rule px-2 py-0.5">Edit</button>
                    <button type="button" wire:click="delete({{ $e->id }})" wire:confirm="Delete this entry?" class="rounded-lg border border-rule px-2 py-0.5">Delete</button>
                </li>
            @endforeach
        </ul>
    @endforeach
</div>
