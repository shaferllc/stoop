@props(['visited' => []])
{{-- Each line drawn like the strip maps above Metro car doors, with stations someone has posted from filled in. --}}
@php($visited = array_flip($visited))
<div {{ $attributes->class('space-y-3') }}>
    @foreach (config('metro.lines') as $line => $stops)
        @php($color = config("stoop.lines.$line.color"))
        @php($done = count(array_intersect_key(array_flip($stops), $visited)))
        <div>
            <div class="mb-1 flex items-center justify-between text-[12px] text-mute">
                <span class="font-semibold capitalize text-ink">{{ $line }} line</span>
                <span class="tabular-nums">{{ $done }}/{{ count($stops) }}{{ $done === count($stops) ? ' · complete' : '' }}</span>
            </div>
            <div class="overflow-x-auto pb-1">
                {{-- inline-flex so the track ends at the last station instead of running to the edge --}}
                <ol class="relative inline-flex items-center gap-[7px] px-1 py-1.5">
                    <span class="absolute inset-x-2.5 top-1/2 h-1 -translate-y-1/2 rounded-full" style="background: {{ $color }}" aria-hidden="true"></span>
                    @foreach ($stops as $code)
                        @php($been = isset($visited[$code]))
                        @php($others = array_values(array_diff(\App\Support\Stations::linesAt($code), [$line])))
                        <li class="relative">
                            <a href="{{ route('stops.show', $code) }}" wire:navigate
                                data-tip="{{ config("stoop.stations.$code") }} · {{ $code }}{{ $others ? ' · also '.implode(', ', array_map('ucfirst', $others)) : '' }} · {{ $been ? 'you’ve posted here' : 'not yet' }}"
                                aria-label="{{ config("stoop.stations.$code") }}, {{ $been ? 'visited' : 'not visited yet' }}"
                                @class(['grid place-items-center rounded-full transition-transform hover:scale-125 focus-visible:scale-125 motion-reduce:transition-none', 'size-[18px]' => $been, 'size-3.5 border-[3px] bg-card' => ! $been])
                                style="{{ $been ? 'background: '.$color.'; box-shadow: 0 0 0 2px var(--color-card), 0 0 0 3.5px '.$color : 'border-color: '.$color }}">
                                @if ($been)<svg viewBox="0 0 12 12" class="size-2.5 text-white" aria-hidden="true"><path d="M2.5 6.2 5 8.5l4.5-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>@endif
                            </a>
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>
    @endforeach
</div>
