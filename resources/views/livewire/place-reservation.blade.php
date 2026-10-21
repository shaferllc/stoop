<div>
    @if ($place->reservation_url || $isOwner)
        <div class="flex flex-wrap items-center gap-2 text-sm">
            @if ($place->reservation_url)
                <a href="{{ route('places.reserve', $place) }}" target="_blank" rel="{{ \App\Support\Referrals::rel($place->reservation_url) }}" class="rounded-lg bg-ink px-3 py-1 font-semibold text-paper">Reserve</a>
                <span class="text-[12.5px] text-mute">on {{ \App\Support\Referrals::name(\App\Support\Referrals::provider($place->reservation_url)) }}@if (\App\Support\Referrals::earns($place->reservation_url)) · {{ \App\Support\Referrals::COMMISSION }}@endif</span>
            @endif
            @if ($isOwner && ! $editing)
                <button type="button" wire:click="edit" class="text-[12.5px] text-mute underline">{{ $place->reservation_url ? 'Change reservation link' : 'Add a reservation link' }}</button>
            @endif
        </div>
    @endif

    @if ($editing)
        <form wire:submit="save" class="mt-2 space-y-2 rounded-lg border border-rule bg-paper p-3 text-sm">
            <label class="block">Reservation link (OpenTable, Resy, Tock or your own site; empty to remove)
                <input type="url" wire:model="url" maxlength="500" placeholder="https://www.opentable.com/r/your-place" class="mt-0.5 block w-full rounded-md border border-rule bg-card px-2 py-1">
            </label>
            @error('url') <p class="text-flag">{{ $message }}</p> @enderror
            <div class="flex gap-2"><button class="rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Save</button><button type="button" wire:click="$set('editing', false)" class="rounded-lg border border-rule px-3 py-1.5">Cancel</button></div>
        </form>
    @endif
</div>
