<div class="space-y-2.5">
    @if ($dispatch->parent)
        <a href="{{ route('dispatches.show', $dispatch->parent) }}" wire:navigate class="block text-sm text-mute hover:text-ink">↑ In reply to {{ '@'.$dispatch->parent->user->username }}: {{ str($dispatch->parent->body)->limit(80) }}</a>
    @else
        <a href="{{ route('home') }}" wire:navigate class="block text-sm text-mute hover:text-ink">← Front Stoop</a>
    @endif

    @if ($dispatch->trashed())
        <div class="rounded-xl border border-dashed border-rule p-5 text-center text-mute">This dispatch was deleted. Replies to it are still here.</div>
    @else
        <x-dispatch-card :dispatch="$dispatch" :state="$state" focus />

        <livewire:composer :parent-id="$dispatch->id" :key="'reply-'.$dispatch->id" />
    @endif

    <h2 class="pt-2 text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">{{ $replies->count() }} {{ str('reply')->plural($replies->count()) }}</h2>
    @foreach ($replies as $reply)
        <x-dispatch-card :dispatch="$reply" :state="$state" />
    @endforeach

    @include('partials.report-dialog')
    @include('partials.undo-bar')
</div>
