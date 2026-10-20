<div>
    <h1 class="mb-3 font-display text-3xl font-bold">Saved</h1>
    <div class="space-y-2.5">
        @forelse ($dispatches as $dispatch)
            <x-dispatch-card :dispatch="$dispatch" :state="$state" />
        @empty
            <div class="rounded-xl border border-dashed border-rule p-8 text-center text-mute">Nothing saved yet. Tap Save on any dispatch to keep it here.</div>
        @endforelse
    </div>
    @include('partials.report-dialog')
    @include('partials.undo-bar')
</div>
