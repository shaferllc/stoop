<div class="space-y-2.5">
    @if ($dispatch->parent)
        <a href="{{ route('dispatches.show', $dispatch->parent) }}" wire:navigate class="block text-sm text-mute hover:text-ink">↑ In reply to {{ $dispatch->parent->user ? '@'.$dispatch->parent->user->username.': '.str($dispatch->parent->body)->limit(80) : 'a deleted dispatch' }}</a>
    @else
        <a href="{{ route('home') }}" wire:navigate class="block text-sm text-mute hover:text-ink">← Front Stoop</a>
    @endif

    @if ($dispatch->trashed())
        <div class="rounded-xl border border-dashed border-rule p-5 text-center text-mute">This dispatch was deleted. Replies to it are still here.</div>
    @else
        <x-dispatch-card :dispatch="$dispatch" :translated="$translations[$dispatch->id] ?? null" :state="$state" focus />

        @if ($dispatch->replies_locked_until?->isFuture())
            <p class="rounded-lg bg-chip px-3 py-2 text-sm text-mute">Replies are paused on this thread until {{ $dispatch->replies_locked_until->format('g:ia') }} while moderators take a look.</p>
        @endif
        <livewire:composer :parent-id="$dispatch->id" :key="'reply-'.$dispatch->id" />
    @endif

    <h2 class="pt-2 text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">{{ $replies->count() }} {{ str('reply')->plural($replies->count()) }}</h2>
    @if ($replies->isNotEmpty())
        <ol class="relative space-y-2.5 pl-6" aria-label="Replies">
            <span class="absolute bottom-4 left-[9px] top-4 w-1 rounded-full" style="background: {{ $dispatch->lineColor() }}" aria-hidden="true"></span>
            @foreach ($replies as $reply)
                <li class="relative" wire:key="reply-{{ $reply->id }}">
                    <span class="absolute -left-[21px] top-5 size-3.5 rounded-full border-[3px] bg-paper" style="border-color: {{ $dispatch->lineColor() }}" aria-hidden="true"></span>
                    @if ($reply->hidden_by_author)
                        <div x-data="{ reveal: false }">
                            <button type="button" x-show="!reveal" x-on:click="reveal = true" class="w-full rounded-xl border border-dashed border-rule p-3 text-sm text-mute">A reply hidden by the author. Show it</button>
                            <div x-show="reveal" x-cloak><x-dispatch-card :dispatch="$reply" :translated="$translations[$reply->id] ?? null" :state="$state" /></div>
                        </div>
                    @else
                        <x-dispatch-card :dispatch="$reply" :translated="$translations[$reply->id] ?? null" :state="$state" />
                    @endif
                </li>
            @endforeach
        </ol>
    @endif

    @include('partials.report-dialog')
    @include('partials.undo-bar')
</div>
