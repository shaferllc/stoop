{{-- A sponsored dispatch or the magazine's party promotion in a feed. Never a Dispatch. --}}
@php($kind = \App\Support\Ads::kindOf($ad))
@php($href = route('ads.click', [$kind, $ad->id]))
<aside wire:key="ad-{{ $kind }}-{{ $ad->id }}" data-ad class="rounded-xl border border-rule bg-card px-4 py-3.5" aria-label="{{ $kind === 'party' ? 'From the magazine' : ($ad->promoted ? 'Promoted' : 'Sponsored') }}">
    <div class="mb-1.5 flex items-start justify-between gap-2 text-[11.5px] font-semibold uppercase tracking-wide text-mute">
        <span>{{ $kind === 'party' ? 'From the magazine' : ($ad->promoted ? 'Promoted · ' : 'Sponsored · ').$ad->advertiser }}</span>
        @include('partials.ad-menu', ['id' => $ad->id, 'why' => $why])
    </div>
    @if ($kind === 'party')
        <a href="{{ $href }}" target="_blank" rel="sponsored noopener" class="block hover:underline">
            <div class="font-display text-lg font-bold">{{ $ad->title }}</div>
            <p class="text-sm text-mute">{{ $ad->event_on->format('D M j') }} · {{ $ad->venue }}@if ($ad->price) · {{ $ad->price }}@endif</p>
        </a>
        <a href="{{ $href }}" target="_blank" rel="sponsored noopener" class="mt-2 inline-block rounded-lg bg-ink px-2.5 py-0.5 text-[12.5px] font-semibold text-paper">Get tickets</a>
    @else
        <a href="{{ $href }}" target="_blank" rel="sponsored noopener" class="block">
            <p class="whitespace-pre-line text-[15px]">{{ $ad->body }}</p>
            @if ($ad->image_path)
                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($ad->image_path) }}" alt="Image from {{ $ad->advertiser }}" loading="lazy" class="mt-2 max-h-96 w-full rounded-lg bg-chip object-cover">
            @endif
            <span class="mt-1 block text-[12.5px] text-mute underline">{{ parse_url($ad->url, PHP_URL_HOST) }}</span>
        </a>
    @endif
</aside>
