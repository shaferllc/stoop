<div class="space-y-3">
    <section class="rounded-xl border border-rule bg-card p-5">
        @if ($place->listLabel())
            <div class="text-[11.5px] font-semibold uppercase tracking-wide text-flag">{{ $place->listLabel() }}</div>
        @endif
        <h1 class="font-display text-4xl font-bold leading-tight">{{ $place->name }}</h1>
        @if (\App\Support\Business::verified($place))<span class="mt-1 inline-flex items-center gap-1 rounded bg-ink px-1.5 text-[11px] font-bold uppercase tracking-wide text-paper"><flux:icon.check-badge variant="micro" /> Verified business</span>@endif
        <div class="mt-1 flex flex-wrap gap-x-3 text-sm text-mute">
            @if ($place->category)<span>{{ $place->category }}</span>@endif
            @if ($place->neighborhood)<span>{{ $place->neighborhood }}</span>@endif
            @if ($place->stop)<a href="{{ route('stops.show', $place->stop) }}" wire:navigate class="font-mono hover:text-ink">{{ $place->stop }} · {{ $place->stopName() }}</a>@endif
            @if ($place->address)<span>{{ $place->address }}</span>@endif
        </div>
        @if ($place->blurb)<p class="mt-3 max-w-prose">{{ $place->blurb }}</p>@endif
        <div class="mt-3 flex flex-wrap items-center gap-3 text-sm">
            <span><b class="tabular-nums">{{ number_format($beenCount) }}</b> {{ $beenCount === 1 ? 'neighbor has' : 'neighbors have' }} been here</span>
            @if ($place->article_url)<a href="{{ $place->article_url }}" target="_blank" rel="noopener" class="underline">Read the review</a>@endif
            @if ($place->website)<a href="{{ $place->website }}" target="_blank" rel="nofollow noopener" class="underline">Website</a>@endif
            @if ($place->owner)
                <span class="rounded bg-chip px-1.5 text-[12px]">Managed by {{ '@'.$place->owner->username }}</span>
                @if ($place->owner_id === auth()->id())
                    @if (\App\Support\Business::isBusiness(auth()->user()))
                        <a href="{{ route('business.analytics', $place) }}" wire:navigate class="underline">Analytics</a>
                    @elseif (\App\Support\Business::canUpgrade())
                        <a href="{{ route('business.edit') }}" wire:navigate class="font-semibold underline">Upgrade to Business</a>
                    @endif
                @endif
            @elseif (! $claiming)
                <button type="button" wire:click="$set('claiming', true)" class="ml-auto text-mute underline">Own this place?</button>
            @endif
        </div>

        @if ($claiming)
            <form wire:submit="claim" class="mt-3 space-y-2 rounded-lg border border-rule bg-paper p-3 text-sm">
                <p class="text-mute">Owners and managers can reply as the business and post specials. Tell us how we can confirm you work here.</p>
                <input wire:model="claimRole" maxlength="80" placeholder="Your role, like Owner or General manager" aria-label="Your role" class="w-full rounded-md border border-rule bg-card px-2.5 py-1.5">
                <textarea wire:model="claimEvidence" rows="2" maxlength="500" placeholder="A work email, a phone number we can call back on, or a link that shows you work here" aria-label="How we can confirm" class="w-full rounded-md border border-rule bg-card px-2.5 py-1.5"></textarea>
                @error('claimRole') <p class="text-flag">{{ $message }}</p> @enderror
                @error('claimEvidence') <p class="text-flag">{{ $message }}</p> @enderror
                <div class="flex gap-2"><button class="rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Send claim</button><button type="button" wire:click="$set('claiming', false)" class="rounded-lg border border-rule px-3 py-1.5">Cancel</button></div>
            </form>
        @endif
    </section>

    <livewire:place-deal-panel :place-id="$place->id" :key="'deal-'.$place->id" />

    @if ($photos->isNotEmpty())
        <div class="grid grid-cols-3 gap-1.5 overflow-hidden rounded-xl">
            @foreach ($photos as $photo)
                <a href="{{ $photo->url() }}" target="_blank"><img src="{{ $photo->url() }}" alt="{{ $photo->alt }}" loading="lazy" class="aspect-square w-full bg-chip object-cover"></a>
            @endforeach
        </div>
    @endif

    <h2 class="pt-1 text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">What neighbors say</h2>
    @forelse ($dispatches as $dispatch)
        <x-dispatch-card :dispatch="$dispatch" :translated="$translations[$dispatch->id] ?? null" :state="$state" />
    @empty
        <x-empty title="Nobody’s weighed in yet" art="stoop">Been to {{ $place->name }}? Tell your neighbors what to order.</x-empty>
    @endforelse

    @include('partials.report-dialog')
    @include('partials.undo-bar')
</div>
