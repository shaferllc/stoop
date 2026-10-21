<div class="mt-2 space-y-2 rounded-lg border border-dashed border-rule bg-paper p-3 text-sm sm:ml-[46px]">
    <div class="text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">For the host</div>

    @foreach (['going' => 'Going', 'interested' => 'Interested'] as $kind => $label)
        <div>
            <b>{{ $label }} ({{ $rsvps[$kind]->count() }})</b>
            <span class="text-mute">
                @forelse ($rsvps[$kind] as $person)
                    <a href="{{ route('users.show', $person) }}" wire:navigate class="hover:text-ink">{{ '@'.$person->username }}</a>@if (! $loop->last), @endif
                @empty
                    Nobody yet.
                @endforelse
            </span>
        </div>
    @endforeach

    <form wire:submit="save" class="grid gap-2 sm:grid-cols-3">
        <label class="text-[12.5px] text-mute">Starts (DC time)<input type="datetime-local" wire:model="form.starts_at" class="mt-0.5 block w-full rounded-md border border-rule bg-card px-2 py-1 text-ink"></label>
        @if ($canTicket)
            <label class="text-[12.5px] text-mute">Ticket price ($)<input type="number" step="0.01" min="0" wire:model="form.price" placeholder="15" class="mt-0.5 block w-full rounded-md border border-rule bg-card px-2 py-1 text-ink"></label>
            <label class="text-[12.5px] text-mute">Ticket link<input type="url" wire:model="form.ticket_url" placeholder="https://" class="mt-0.5 block w-full rounded-md border border-rule bg-card px-2 py-1 text-ink"></label>
        @endif
        <div class="flex flex-wrap items-center gap-2 sm:col-span-3">
            <button class="rounded-lg bg-flag px-3 py-1 font-semibold text-white">Save</button>
            @if ($happening?->ticket_url)<span class="text-mute">{{ number_format($happening->ticket_clicks) }} {{ str('click')->plural($happening->ticket_clicks) }} on Get tickets</span>@endif
            @if (auth()->user()->is_staff)
                <button type="button" wire:click="toggleFeatured" class="ml-auto rounded-lg border border-rule px-3 py-1">{{ $happening?->featured ? 'Unfeature' : 'Feature (staff)' }}</button>
            @endif
        </div>
        @foreach (['starts_at', 'price', 'ticket_url'] as $field) @error($field) <p class="text-flag sm:col-span-3">{{ $message }}</p> @enderror @endforeach
    </form>
</div>
