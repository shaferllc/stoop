<div class="space-y-3">
    @php($input = 'mt-0.5 block w-full rounded-md border border-rule bg-card px-2 py-1')
    @if (session('status'))<p class="rounded-lg bg-chip px-3 py-2 text-sm" role="status">{{ session('status') }}</p>@endif
    <a href="{{ route('classifieds', ['category' => $listing->category]) }}" wire:navigate class="text-sm text-mute hover:text-ink">← {{ $listing->categoryName() }}</a>

    <section class="rounded-xl border border-rule bg-card p-5">
        <div class="flex flex-wrap gap-x-2 text-[12px] text-mute">
            <span class="font-semibold uppercase tracking-wide text-flag">{{ $listing->categoryName() }}</span>
            @if ($listing->stop)<a href="{{ route('stops.show', $listing->stop) }}" wire:navigate class="hover:text-ink">· near {{ $listing->stopName() }}</a>@endif
            <span>· posted {{ $listing->created_at->diffForHumans() }} by {{ '@'.$listing->user->username }}</span>
        </div>
        <h1 class="font-display text-3xl font-bold leading-tight">{{ $listing->title }}</h1>
        @if ($listing->priceLabel())<p class="mt-1 text-lg font-semibold tabular-nums">{{ $listing->priceLabel() }}</p>@endif
        <p class="mt-3 max-w-prose whitespace-pre-line">{{ $listing->body }}</p>

        @if (! $listing->isLive())
            <p class="mt-3 rounded-lg bg-chip px-3 py-2 text-sm"><b>{{ $listing->statusLabel() }}.</b> Only you{{ $mine ? '' : ' (staff)' }} can see this.</p>
        @elseif ($listing->expires_at)
            <p class="mt-3 text-[12.5px] text-mute">Runs until {{ $listing->expires_at->timezone(config('stoop.timezone'))->format('M j') }}.</p>
        @endif
    </section>

    @if ($mine)
        <section class="flex flex-wrap items-center gap-2 rounded-xl border border-rule bg-card p-4 text-sm">
            <b>Your listing:</b> {{ $listing->statusLabel() }}
            @if ($listing->status === 'unpaid')
                @if ($stripe)
                    <button type="button" wire:click="pay" class="rounded-lg bg-flag px-3 py-1 font-semibold text-white">Pay {{ \App\Support\Classifieds::priceLabel($listing->category) }}</button>
                @else
                    <span class="text-mute">Paid listings are coming soon.</span>
                @endif
            @elseif ($listing->status === 'active')
                @if (\App\Support\Classifieds::price($listing->category) === 0 || $stripe)
                    <button type="button" wire:click="renew" class="rounded-lg border border-rule px-3 py-1">Renew for {{ config('classifieds.days') }} days{{ \App\Support\Classifieds::price($listing->category) ? ' ('.\App\Support\Classifieds::priceLabel($listing->category).')' : '' }}</button>
                @endif
                <button type="button" wire:click="markFilled" wire:confirm="Take this listing down?" class="rounded-lg border border-rule px-3 py-1">{{ $listing->category === 'forsale' ? 'Mark sold' : 'Mark filled' }}</button>
            @endif
        </section>
    @elseif ($listing->isLive())
        <form wire:submit="contact" class="space-y-2 rounded-xl border border-rule bg-card p-4 text-sm">
            <label class="block font-semibold">Message {{ '@'.$listing->user->username }}
                <textarea wire:model="message" rows="3" maxlength="1000" placeholder="Hi! Is this still available?" class="{{ $input }} font-normal"></textarea>
            </label>
            @error('message') <p class="text-flag">{{ $message }}</p> @enderror
            <p class="text-[12px] text-mute">Sent as a Stoop message. If they don’t follow you, it waits as a request. Meet in public, and never pay a deposit before you’ve seen the place or the item.</p>
            <div class="flex items-center gap-2">
                <button class="rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Send</button>
                @unless ($reporting)<button type="button" wire:click="$set('reporting', true)" class="ml-auto text-mute underline">Report</button>@endunless
            </div>
        </form>

        @if ($reporting)
            <form wire:submit="report" class="space-y-2 rounded-xl border border-rule bg-card p-4 text-sm">
                <label class="block">Why?<select wire:model="reason" class="{{ $input }}">
                    <option value="scam">Scam or fake listing</option>
                    <option value="discrimination">Discriminates (fair housing or hiring)</option>
                    @foreach (config('stoop.report_reasons') as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </select></label>
                <label class="block">Anything else (optional)<textarea wire:model="note" rows="2" maxlength="500" class="{{ $input }}"></textarea></label>
                <div class="flex gap-2"><button class="rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Send report</button><button type="button" wire:click="$set('reporting', false)" class="rounded-lg border border-rule px-3 py-1.5">Cancel</button></div>
            </form>
        @endif
    @endif
</div>
