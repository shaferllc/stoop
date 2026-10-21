<div>
    <h1 class="mb-3 font-display text-3xl font-bold">Saved</h1>
    <div class="space-y-2.5">
        @forelse ($dispatches as $dispatch)
            <x-dispatch-card :dispatch="$dispatch" :translated="$translations[$dispatch->id] ?? null" :state="$state" />
        @empty
            <x-empty title="Nothing saved yet">Tap the bookmark on any dispatch to keep it here for later.</x-empty>
        @endforelse
    </div>
    @include('partials.report-dialog')
    @include('partials.undo-bar')
</div>
