{{-- A small team badge in the team's colors. Needs $key; nothing for games added by hand. --}}
@if ($key && $t = config('sports.teams.'.$key))
    <a href="{{ route('sports.team', $key) }}" wire:navigate class="inline-flex items-center rounded px-1.5 text-[11px] font-bold leading-5" style="background: {{ $t['colors'][0] }}; color: #fff; box-shadow: inset 0 -2px 0 {{ $t['colors'][1] }}" title="{{ $t['full'] }}">{{ $t['name'] }}</a>
@endif
