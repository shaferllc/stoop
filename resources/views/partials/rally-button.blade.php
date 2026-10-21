{{-- Rally: the game-day cheer on game threads, toggled like mumbo sauce. Uses the card's $d, $me, $marks and $act. --}}
@php($rallies = $d->markers()->where('marks.kind', 'rally')->count())
@php($rally = '<svg viewBox="0 0 24 24" aria-hidden="true" class="size-4"><path d="M5 3v18" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M5.8 4 19 8.2 5.8 12.4Z" fill="#d9442b"/></svg>')
@if ($me)
    <button type="button" wire:click="toggleMark({{ $d->id }}, 'rally')" @class([$act, 'font-semibold text-flag' => in_array('rally', $marks)]) title="Rally" aria-pressed="{{ in_array('rally', $marks) ? 'true' : 'false' }}">
        {!! $rally !!}<span class="sr-only">Rally</span>{{ $rallies ?: '' }}
    </button>
@elseif ($rallies)
    <span class="{{ $act }}" title="Rally">{!! $rally !!}{{ $rallies }}</span>
@endif
