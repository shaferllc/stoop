@if ($undoId)
    <div wire:key="undo-{{ $undoId }}" x-data x-init="setTimeout(() => $wire.set('undoId', null), 30000)"
        class="fixed bottom-5 left-1/2 z-30 flex -translate-x-1/2 items-center gap-4 rounded-lg bg-ink px-4 py-2.5 text-sm text-paper shadow-lg" role="status">
        Dispatch deleted.
        <button type="button" wire:click="restoreDispatch({{ $undoId }})" class="font-semibold underline">Undo</button>
    </div>
@endif
