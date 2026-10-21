@props(['code', 'line' => null])
{{-- A WMATA-style station marker: the code in a colored roundel, then the station name. --}}
<span {{ $attributes->class('inline-flex items-center gap-1.5') }}>
    <span class="grid h-5 min-w-5 place-items-center rounded-full px-1 font-mono text-[10px] font-bold leading-none text-white" style="background: {{ $line ? config("stoop.lines.$line.color") : '#5d6366' }}">{{ $code }}</span>
    <span>{{ config("stoop.stations.$code") }}</span>
</span>
