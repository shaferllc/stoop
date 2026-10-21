{{-- The composer's open house form. Posts on the Green (Real Estate) line at the stop picked below. --}}
<div class="mt-2 grid gap-2 rounded-lg border border-rule bg-paper p-2.5 text-sm sm:grid-cols-2">
    <p class="text-[12.5px] text-mute sm:col-span-2">Open houses post on the Green line. Give the street address and Stoop shows only the block. Pick the nearest stop below.</p>
    <label class="sm:col-span-2">Address<input wire:model="openHouse.block" maxlength="120" placeholder="1428 Q St NW" class="mt-0.5 block w-full rounded-md border border-rule bg-card px-2 py-1"></label>
    <label>Date<input type="date" wire:model="openHouse.date" class="mt-0.5 block w-full rounded-md border border-rule bg-card px-2 py-1"></label>
    <div class="grid grid-cols-2 gap-2">
        <label>From<input type="time" wire:model="openHouse.from" class="mt-0.5 block w-full rounded-md border border-rule bg-card px-2 py-1"></label>
        <label>To<input type="time" wire:model="openHouse.to" class="mt-0.5 block w-full rounded-md border border-rule bg-card px-2 py-1"></label>
    </div>
    <label>Price (optional)<input type="number" min="1000" step="1000" wire:model="openHouse.price" placeholder="725000" class="mt-0.5 block w-full rounded-md border border-rule bg-card px-2 py-1"></label>
    <label>Listing link (optional)<input type="url" wire:model="openHouse.url" maxlength="300" placeholder="https://" class="mt-0.5 block w-full rounded-md border border-rule bg-card px-2 py-1"></label>
    <label class="sm:col-span-2">Listing agent (optional)<input wire:model="openHouse.agent" maxlength="120" placeholder="Name and brokerage" class="mt-0.5 block w-full rounded-md border border-rule bg-card px-2 py-1"></label>
    @foreach (['block', 'date', 'from', 'to', 'price', 'url', 'agent'] as $field)
        @error('openHouse.'.$field) <p class="text-sm text-flag sm:col-span-2">{{ $message }}</p> @enderror
    @endforeach
</div>
