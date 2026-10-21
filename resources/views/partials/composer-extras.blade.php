{{-- Composer fields for Happenings (calendar start time) and Lost & found posts. --}}
@if ($type === 'happening' && ! $parentId)
    <label class="mt-2 flex flex-wrap items-center gap-2 text-[13px] text-mute">
        Starts (for the calendar)
        <input type="datetime-local" wire:model="happeningStarts" class="rounded-md border border-rule bg-paper px-1.5 py-1 text-ink">
    </label>
    @error('happeningStarts') <p class="mt-1 text-sm text-flag">{{ $message }}</p> @enderror
@endif

@if ($type === 'lost' && ! $parentId)
    <div class="mt-2 space-y-1.5 rounded-lg border border-rule bg-paper p-2.5 text-sm">
        <div class="grid gap-1.5 sm:grid-cols-2">
            <select wire:model="lostStatus" aria-label="Lost or found" class="rounded-md border border-rule bg-card px-2 py-1">
                <option value="">Lost or found?</option>
                @foreach (\App\Support\LostAndFound::STATUSES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
            <select wire:model="lostKind" aria-label="Pet or item" class="rounded-md border border-rule bg-card px-2 py-1">
                <option value="">Pet or item?</option>
                @foreach (\App\Support\LostAndFound::KINDS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
        </div>
        <p class="text-[12.5px] text-mute">Pick the stop it was last seen near, and add a photo if you have one. Leave out phone numbers and emails: neighbors reply or message you on Stoop.</p>
    </div>
    @error('lostStatus') <p class="mt-1 text-sm text-flag">{{ $message }}</p> @enderror
    @error('lostKind') <p class="mt-1 text-sm text-flag">{{ $message }}</p> @enderror
@endif
