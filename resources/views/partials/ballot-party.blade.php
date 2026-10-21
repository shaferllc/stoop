{{-- The magazine's party for this ballot, until it has happened. Tickets are an outside link. --}}
@php($party = \App\Models\BallotPromotion::where('ballot_id', $ballot->id)->whereDate('event_on', '>=', now(config('stoop.timezone'))->toDateString())->first())
@if ($party)
    <section class="rounded-xl border border-rule bg-card p-4">
        <div class="text-[11.5px] font-semibold uppercase tracking-wide text-mute">From the magazine</div>
        <h2 class="font-display text-xl font-bold">{{ $party->title }}</h2>
        <p class="text-sm text-mute">{{ $party->event_on->format('l, F j') }} · {{ $party->venue }}@if ($party->price) · {{ $party->price }}@endif</p>
        <a href="{{ route('ads.click', ['party', $party->id]) }}" target="_blank" rel="sponsored noopener" class="mt-2 inline-block rounded-lg bg-ink px-2.5 py-0.5 text-[12.5px] font-semibold text-paper">Get tickets</a>
    </section>
@endif
