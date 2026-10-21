@props(['code', 'line' => null])
{{-- A WMATA-style station marker: the code in a colored roundel, then the station name. Hover for lines and jurisdiction. --}}
@php($lines = \App\Support\Stations::linesAt($code))
@php($where = ['DC' => 'DC', 'MD' => 'Maryland', 'VA' => 'Virginia'][\App\Support\Stations::jurisdiction($code)] ?? null)
<span {{ $attributes->class('inline-flex items-center gap-1.5') }}
    data-tip="{{ config("stoop.stations.$code") }} ({{ $code }}) · {{ implode(', ', array_map(fn ($l) => ucfirst($l), $lines)) }} {{ count($lines) === 1 ? 'line' : 'lines' }}{{ $where ? ' · '.$where : '' }}">
    <span class="grid h-5 min-w-5 place-items-center rounded-full px-1 font-mono text-[10px] font-bold leading-none text-white" style="background: {{ $line ? config("stoop.lines.$line.color") : config('stoop.lines.'.($lines[0] ?? 'silver').'.color') }}">{{ $code }}</span>
    <span>{{ config("stoop.stations.$code") }}</span>
</span>
