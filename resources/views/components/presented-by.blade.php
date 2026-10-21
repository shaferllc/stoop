{{-- "Presented by" a game's sponsor, if one is running. Counts the impression; the link goes through the ad click redirect. --}}
@props(['target'])
@php($presenter = \App\Models\Presenter::show($target))
@if ($presenter)
    <a href="{{ route('ads.click', ['presenter', $presenter->id]) }}" target="_blank" rel="sponsored noopener" data-presented-by {{ $attributes->class('inline-flex items-center gap-1.5 text-[12px] text-mute hover:text-ink') }}>
        @if ($presenter->logo_path)<img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($presenter->logo_path) }}" alt="" loading="lazy" class="h-5 w-auto max-w-20 object-contain">@endif
        <span>Presented by <b>{{ $presenter->sponsor }}</b></span>
    </a>
@endif
