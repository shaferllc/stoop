{{-- Happenings hosting a watch party for a game, by Metro stop. Needs $parties. --}}
@if ($parties->isNotEmpty())
    <section>
        <h2 class="mb-2 text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">Watch parties near Metro</h2>
        <ul class="divide-y divide-rule rounded-xl border border-rule bg-card text-sm">
            @foreach ($parties->sortBy('stop') as $party)
                <li class="px-4 py-2.5">
                    <a href="{{ route('dispatches.show', $party) }}" wire:navigate class="font-semibold hover:underline">{{ str($party->body)->limit(90) }}</a>
                    <div class="text-[13px] text-mute">
                        {{ $party->stop ? $party->stop.' · '.config('stoop.stations.'.$party->stop) : 'No stop given' }}
                        @if ($party->happening_at) · {{ $party->happening_at }} @endif
                        · {{ '@'.$party->user->username }}
                    </div>
                </li>
            @endforeach
        </ul>
    </section>
@endif
