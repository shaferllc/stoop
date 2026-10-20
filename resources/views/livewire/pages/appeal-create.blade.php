<form wire:submit="submit" class="rounded-xl border border-rule bg-card p-6">
    <h1 class="font-display text-3xl font-bold">Appeal a decision</h1>
    <p class="mt-1 text-mute">A different moderator from the one who made the call will read this. You can appeal each decision once.</p>

    <div class="mt-4 rounded-lg bg-chip px-3 py-2 text-sm">
        @if ($target instanceof \App\Models\Penalty)
            <b>{{ $target->label() }}</b> · {{ $target->reason }}
        @else
            <b>Removed dispatch</b> · {{ $target->moderation_reason }}
            <p class="mt-1 text-mute">“{{ str($target->body)->limit(200) }}”</p>
        @endif
    </div>

    <label for="appeal-body" class="mt-4 block text-sm font-medium">Why should we reconsider?</label>
    <textarea id="appeal-body" wire:model="body" rows="5" maxlength="1000" class="mt-1 w-full rounded-lg border border-rule bg-paper p-2.5"></textarea>
    @error('body') <p class="mt-1 text-sm text-flag">{{ $message }}</p> @enderror

    <button type="submit" class="mt-3 rounded-lg bg-flag px-4 py-2 text-sm font-semibold text-white">Send appeal</button>
</form>
