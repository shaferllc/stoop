<div>
    @php($input = 'mt-0.5 block w-full rounded-md border border-rule bg-card px-2 py-1')
    @if ($active)
        <section class="rounded-xl border border-flag/40 bg-flag/5 p-4">
            <div class="text-[11px] font-semibold uppercase tracking-wide text-flag">Deal from the owner</div>
            <h2 class="font-display text-xl font-bold">{{ $active->title }}</h2>
            @if ($active->details)<p class="whitespace-pre-line text-sm">{{ $active->details }}</p>@endif
            <p class="mt-1 text-[12.5px] text-mute">Until {{ $active->ends_at->timezone(config('stoop.timezone'))->format('D M j, g:ia') }}</p>
            @if ($isOwner)<button type="button" wire:click="end({{ $active->id }})" wire:confirm="End this deal now?" class="mt-1 text-[12.5px] text-mute underline">End it now</button>@endif
        </section>
    @endif

    @if ($isOwner)
        @if ($upcoming)
            <p class="mt-2 text-sm text-mute">Scheduled: <b class="text-ink">{{ $upcoming->title }}</b> from {{ $upcoming->starts_at->timezone(config('stoop.timezone'))->format('D M j, g:ia') }}. <button type="button" wire:click="end({{ $upcoming->id }})" class="underline">Cancel</button></p>
        @elseif (! $active && ! $posting)
            <button type="button" wire:click="$set('posting', true)" class="rounded-lg border border-rule px-3 py-1.5 text-sm font-semibold">Post a deal</button>
        @endif

        @if ($posting && ! $active && ! $upcoming)
            <form wire:submit="post" class="space-y-2 rounded-xl border border-rule bg-card p-4 text-sm">
                <p class="text-mute">One deal at a time. It shows on this page and, labeled, at the top of the {{ $place->stopName() ?? 'stop' }} feed.</p>
                <label class="block">Title<input wire:model="deal.title" maxlength="80" placeholder="Half-price half-smokes before 6" class="{{ $input }}"></label>
                <label class="block">Details (optional)<textarea wire:model="deal.details" rows="2" maxlength="500" class="{{ $input }}"></textarea></label>
                <div class="grid grid-cols-2 gap-2">
                    <label>Starts (DC time, empty for now)<input type="datetime-local" wire:model="deal.starts_at" class="{{ $input }}"></label>
                    <label>Ends (DC time)<input type="datetime-local" wire:model="deal.ends_at" class="{{ $input }}"></label>
                </div>
                @foreach (['deal.title', 'deal.details', 'deal.starts_at', 'deal.ends_at'] as $f) @error($f) <p class="text-flag">{{ $message }}</p> @enderror @endforeach
                <div class="flex gap-2"><button class="rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Post deal</button><button type="button" wire:click="$set('posting', false)" class="rounded-lg border border-rule px-3 py-1.5">Cancel</button></div>
            </form>
        @endif
    @endif
</div>
