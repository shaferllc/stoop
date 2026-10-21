<div class="space-y-3">
    @php($tz = config('stoop.timezone'))
    <section class="rounded-xl border border-rule bg-card p-6">
        <div class="text-[11.5px] font-semibold uppercase tracking-wide text-flag">Ask the editor</div>
        <h1 class="font-display text-3xl font-bold">{{ $thread->title }}</h1>
        <p class="mt-1 text-sm text-mute">
            With <a href="{{ route('users.show', $thread->editor) }}" wire:navigate class="font-semibold text-ink">{{ $thread->editor->name }}</a> ·
            @if ($thread->isOpen())
                Questions open until {{ $thread->ends_at->timezone($tz)->format('D M j, g:ia') }}
            @elseif ($thread->starts_at->isFuture())
                Questions open {{ $thread->starts_at->timezone($tz)->format('D M j, g:ia') }}
            @else
                Closed {{ $thread->ends_at->timezone($tz)->format('M j') }}
            @endif
            · <a href="{{ route('dispatches.show', $thread->dispatch_id) }}" wire:navigate class="underline">Thread</a>
        </p>

        @if ($thread->isOpen())
            @auth
                <form wire:submit="ask" class="mt-3">
                    <textarea wire:model.live.debounce.400ms="question" rows="2" maxlength="{{ config('stoop.max_length') }}" placeholder="Ask {{ $thread->editor->name }} a question" aria-label="Your question" class="w-full rounded-md border border-rule bg-paper px-2.5 py-1.5 text-sm"></textarea>
                    @if ($warned)<p class="mt-1 text-sm text-flag" role="alert"><b>Would you say this to a neighbor?</b> Edit it, or ask again to post as is.</p>@endif
                    @error('question') <p class="mt-1 text-sm text-flag">{{ $message }}</p> @enderror
                    @error('body') <p class="mt-1 text-sm text-flag">{{ $message }}</p> @enderror
                    <button class="mt-1.5 rounded-lg bg-flag px-3.5 py-1.5 text-[13px] font-semibold text-white">Ask</button>
                </form>
            @else
                <p class="mt-3 text-sm"><a href="{{ route('login') }}" class="underline">Log in</a> to ask a question.</p>
            @endauth
        @endif
    </section>

    @forelse ($questions as $q)
        <article wire:key="q-{{ $q->id }}" class="flex gap-3 rounded-xl border border-rule bg-card p-4">
            <div class="flex flex-col items-center">
                @auth
                    <button type="button" wire:click="upvote({{ $q->id }})" @disabled($q->user_id === auth()->id()) aria-pressed="{{ $q->voted ? 'true' : 'false' }}" aria-label="Upvote" @class(['rounded-md px-1.5 hover:bg-chip disabled:opacity-40', 'text-flag' => $q->voted, 'text-mute' => ! $q->voted])>
                        <flux:icon.chevron-up variant="mini" />
                    </button>
                @endauth
                <span class="text-sm font-bold tabular-nums">{{ $q->votes }}</span>
            </div>
            <div class="min-w-0 flex-1">
                <div class="text-[12.5px] text-mute"><a href="{{ route('users.show', $q->user) }}" wire:navigate class="font-semibold text-ink">{{ '@'.$q->user->username }}</a> asked</div>
                <p class="whitespace-pre-line break-words">{{ $q->body }}</p>
                @if ($q->status !== 'approved')<p class="mt-1 text-[12px] text-mute">Only you can see this until it passes the house rules check.</p>@endif

                @foreach ($q->answers as $a)
                    <div class="mt-2 rounded-lg border-l-3 border-flag bg-paper px-3 py-2">
                        <div class="text-[11.5px] font-semibold uppercase tracking-wide text-flag">{{ $thread->editor->name }} answered</div>
                        <p class="whitespace-pre-line break-words">{{ $a->body }}</p>
                    </div>
                @endforeach

                @if ($isEditor && $q->status === 'approved')
                    <form wire:submit="answer({{ $q->id }})" class="mt-2 flex gap-2">
                        <input wire:model="answers.{{ $q->id }}" maxlength="{{ config('stoop.max_length') }}" placeholder="Answer this one" aria-label="Answer" class="min-w-0 flex-1 rounded-md border border-rule bg-paper px-2.5 py-1 text-sm">
                        <button class="rounded-lg border border-rule px-3 py-1 text-[13px] font-semibold">Answer</button>
                    </form>
                @endif
            </div>
        </article>
    @empty
        <x-empty title="No questions yet">{{ $thread->isOpen() ? 'Ask the first one.' : 'Questions open soon.' }}</x-empty>
    @endforelse
</div>
