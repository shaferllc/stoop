@props(['visited' => []])
{{-- Each line drawn like the strip maps above Metro car doors, with stations someone has posted from filled in. --}}
@php($visited = array_flip($visited))
<div {{ $attributes->class('space-y-2.5') }}>
    @foreach (config('metro.lines') as $line => $stops)
        @php($color = config("stoop.lines.$line.color"))
        @php($done = count(array_intersect_key(array_flip($stops), $visited)))
        <div>
            <div class="mb-1 flex items-center justify-between text-[12px] text-mute">
                <span class="font-semibold capitalize text-ink">{{ $line }} line</span>
                <span class="tabular-nums">{{ $done }}/{{ count($stops) }}</span>
            </div>
            <div class="overflow-x-auto pb-1">
                <ol class="relative flex min-w-max items-center gap-[7px] px-1 py-1.5">
                    <span class="absolute inset-x-1 top-1/2 h-1 -translate-y-1/2 rounded-full" style="background: {{ $color }}" aria-hidden="true"></span>
                    @foreach ($stops as $code)
                        @php($been = isset($visited[$code]))
                        <li class="relative">
                            <a href="{{ route('stops.show', $code) }}" wire:navigate title="{{ $code }} · {{ config("stoop.stations.$code") }}{{ $been ? ' ✓' : '' }}"
                                @class(['block size-3.5 rounded-full border-[3px]', 'border-card' => $been, 'border-[color:var(--line)] bg-card' => ! $been])
                                style="--line: {{ $color }}; {{ $been ? 'background: '.$color.'; box-shadow: 0 0 0 1.5px '.$color : '' }}">
                                <span class="sr-only">{{ config("stoop.stations.$code") }}{{ $been ? ', visited' : '' }}</span>
                            </a>
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>
    @endforeach
</div>
