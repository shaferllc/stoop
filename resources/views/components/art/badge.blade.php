@props(['key', 'badge', 'size' => 44])
@php
    // Each badge gets a Metro line color and a two-letter station-style code.
    $colors = array_column(config('stoop.lines'), 'color');
    $color = $colors[crc32($key) % count($colors)];
    $code = collect(explode(' ', str_replace(['-', 'The '], [' ', ''], $badge['name'])))->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->join('');
@endphp
<span class="inline-flex flex-col items-center gap-1 text-center" title="{{ $badge['description'] }}">
    <svg viewBox="0 0 44 44" width="{{ $size }}" height="{{ $size }}" aria-hidden="true">
        <circle cx="22" cy="22" r="20" fill="{{ $color }}" />
        <circle cx="22" cy="22" r="15.5" fill="none" stroke="#fff" stroke-width="2" stroke-dasharray="3 2.2" opacity=".9" />
        <text x="22" y="26.5" text-anchor="middle" font-family="IBM Plex Mono, ui-monospace, monospace" font-size="12" font-weight="700" fill="#fff">{{ strtoupper($code) }}</text>
    </svg>
    <span class="max-w-20 text-[11.5px] leading-tight">{{ $badge['name'] }}</span>
</span>
