{{-- Owners' deals at places near this stop, labeled, at the top of the stop's feed. Views and clicks count for the owner's analytics. --}}
@foreach (\App\Models\PlaceDeal::active()->whereHas('place', fn ($q) => $q->where('stop', $stop))->with('place')->latest()->take(3)->get() as $deal)
    @php(\App\Support\Business::count($deal->place_id, 'deal_views'))
    <a href="{{ route('deals.open', $deal) }}" wire:key="deal-{{ $deal->id }}" class="mb-3 block rounded-xl border border-rule bg-card p-3.5 hover:border-ink">
        <div class="text-[11px] font-semibold uppercase tracking-wide text-flag">Deal · {{ $deal->place->name }}</div>
        <div class="font-display text-lg font-bold">{{ $deal->title }}</div>
        @if ($deal->details)<p class="line-clamp-2 text-sm text-mute">{{ $deal->details }}</p>@endif
        <p class="text-[12px] text-mute">Posted by the owner · until {{ $deal->ends_at->timezone(config('stoop.timezone'))->format('D M j, g:ia') }}</p>
    </a>
@endforeach
