<form wire:submit="submit" class="rounded-xl border border-rule bg-card p-6">
    <h1 class="font-display text-3xl font-bold">Tip the newsroom</h1>
    <p class="mt-1 text-mute">Seen something the magazine should look into? Tips go straight to our editors and are never posted on Stoop.</p>

    @if ($sent)
        <p class="mt-3 rounded-lg bg-chip px-3 py-2 text-sm" role="status">Got it. Editors read every tip. Send another below if you have more.</p>
    @endif

    @if ($about)
        <div class="mt-4 rounded-lg bg-chip px-3 py-2 text-sm">
            <b>About this dispatch</b> from {{ '@'.$about->user->username }}
            <p class="mt-1 text-mute">“{{ str($about->body)->limit(200) }}”</p>
        </div>
    @endif

    <label for="tip-body" class="mt-4 block text-sm font-medium">What should we know?</label>
    <textarea id="tip-body" wire:model="body" rows="6" maxlength="2000" class="mt-1 w-full rounded-lg border border-rule bg-paper p-2.5"></textarea>
    @error('body') <p class="mt-1 text-sm text-flag">{{ $message }}</p> @enderror
    @error('dispatchId') <p class="mt-1 text-sm text-flag">{{ $message }}</p> @enderror

    <label for="tip-link" class="mt-3 block text-sm font-medium">A link (optional)</label>
    <input id="tip-link" type="url" wire:model="link" maxlength="500" placeholder="https://" class="mt-1 w-full rounded-lg border border-rule bg-paper px-2.5 py-1.5">
    @error('link') <p class="mt-1 text-sm text-flag">{{ $message }}</p> @enderror

    <label class="mt-3 block text-sm font-medium">Photos (optional, up to 3)
        <input type="file" wire:model="photos" multiple accept="image/jpeg,image/png,image/webp" class="mt-1 block text-sm">
    </label>
    <p class="mt-1 text-[12.5px] text-mute">Only editors see them. Location and other photo data are removed.</p>
    @error('photos') <p class="mt-1 text-sm text-flag">{{ $message }}</p> @enderror
    @error('photos.*') <p class="mt-1 text-sm text-flag">{{ $message }}</p> @enderror

    <label class="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" wire:model="contactOk"> OK to contact me about this in Stoop messages</label>

    <button type="submit" wire:loading.attr="disabled" class="mt-4 rounded-lg bg-flag px-4 py-2 text-sm font-semibold text-white">Send tip</button>
</form>
