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
        @include('partials.sports-thread')

        @if ($dispatch->replies_locked_until?->isFuture())
            <p class="rounded-lg bg-chip px-3 py-2 text-sm text-mute">Replies are paused on this thread until {{ $dispatch->replies_locked_until->format('g:ia') }} while moderators take a look.</p>
        @endif
        <livewire:composer :parent-id="$dispatch->id" :key="'reply-'.$dispatch->id" />
    @endif

    <h2 class="pt-2 text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">{{ $replies->count() }} {{ str('reply')->plural($replies->count()) }}</h2>
    @if ($replies->isNotEmpty())
        @include('partials.reply-tree', ['parentId' => $dispatch->id, 'depth' => 0])
    @endif

    @include('partials.report-dialog')
    @include('partials.undo-bar')
</div>
