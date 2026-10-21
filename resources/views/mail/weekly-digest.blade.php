<x-mail::message>
# The week on Stoop

Week of {{ $digest['week_of'] }}{{ $digest['new_people'] ? ' · '.$digest['new_people'].' new neighbors joined' : '' }}

@if ($digest['talk'])
## Talk of the town

“{{ str($digest['talk']->body)->limit(200) }}”
{{ '@'.$digest['talk']->user->username }} · {{ $digest['talk']->replies_count }} replies · [Join in]({{ route('dispatches.show', $digest['talk']) }})
@endif

@if ($digest['top']->isNotEmpty())
## The week’s best dispatches

@foreach ($digest['top'] as $d)
- **{{ '@'.$d->user->username }}**{{ $d->stop ? ' at '.$d->stopName() : '' }}: {{ str($d->body)->limit(160) }} [Read]({{ route('dispatches.show', $d) }})
@endforeach
@endif

@if ($digest['polls']->isNotEmpty())
## Polls, settled

@foreach ($digest['polls'] as $poll)
- {{ str($poll['question'])->limit(100) }} **{{ $poll['winner']?->label }}** won, out of {{ number_format($poll['votes']) }} votes. [See results]({{ $poll['url'] }})
@endforeach
@endif

@if (($digest['rivalries'] ?? collect())->isNotEmpty())
## Rivalry results

@foreach ($digest['rivalries'] as $m)
- **{{ config('stoop.stations.'.$m['leader']) }}** won {{ $m['title'] }}, {{ implode('–', array_values($m['stops'])) }}.
@endforeach
@endif

@if ($digest['places']->isNotEmpty())
## Where people went

@foreach ($digest['places'] as $place)
- [{{ $place->name }}]({{ route('places.show', $place) }}){{ $place->neighborhood ? ', '.$place->neighborhood : '' }} · {{ $place->recent_count }} {{ str('post')->plural($place->recent_count) }}
@endforeach
@endif

<x-mail::button :url="route('home')">
Open Stoop
</x-mail::button>

@if ($unsubscribe)
<small>You get this because you turned on the weekly email. [Unsubscribe]({{ $unsubscribe }}).</small>
@endif
</x-mail::message>
