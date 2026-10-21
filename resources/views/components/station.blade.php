@props(['code'])
{{-- A WMATA-style station marker: the code in a roundel colored by the Metro lines that stop
     there (split for transfer stations), then the name. Hover for lines and jurisdiction. --}}
@php
    $lines = \App\Support\Stations::linesAt($code);
    $colors = array_map(fn ($l) => config("stoop.lines.$l.color"), $lines ?: ['silver']);
    $step = 360 / count($colors);
    $fill = count($colors) === 1
        ? $colors[0]
        : 'conic-gradient('.implode(', ', array_map(fn ($c, $i) => $c.' '.($i * $step).'deg '.(($i + 1) * $step).'deg', $colors, array_keys($colors))).')';
    $where = ['DC' => 'DC', 'MD' => 'Maryland', 'VA' => 'Virginia'][\App\Support\Stations::jurisdiction($code)] ?? null;
@endphp
<span {{ $attributes->class('inline-flex items-center gap-1.5') }}
    data-tip="{{ config("stoop.stations.$code") }} ({{ $code }}) · {{ implode(', ', array_map('ucfirst', $lines)) }} {{ count($lines) === 1 ? 'line' : 'lines' }}{{ $where ? ' · '.$where : '' }}">
    <span class="grid h-5 min-w-5 place-items-center rounded-full px-1 font-mono text-[10px] font-bold leading-none text-white [text-shadow:0_0_2px_rgb(0_0_0/0.6)]" style="background: {{ $fill }}">{{ $code }}</span>
    <span>{{ config("stoop.stations.$code") }}</span>
</span>
