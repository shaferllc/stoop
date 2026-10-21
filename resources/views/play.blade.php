<x-layouts::stoop title="Play">
    <div class="space-y-4">
        @include('partials.games-nav')
        <div>
            <h1 class="font-display text-3xl font-bold">Play</h1>
            <p class="text-mute">Every game on Stoop in one place. Points and badges from all of them add up on your profile.</p>
        </div>

        <ul class="grid gap-2 sm:grid-cols-2">
            @foreach ($cards as $card)
                <li class="flex flex-col gap-1"><a href="{{ $card['url'] }}" wire:navigate class="flex flex-1 flex-col rounded-xl border border-rule bg-card p-4 hover:border-ink">
                    <span class="text-[11px] font-semibold uppercase tracking-wide {{ $card['live'] ? 'text-flag' : 'text-mute' }}">{{ $card['when'] }}</span>
                    <span class="font-display text-lg font-bold">{{ $card['title'] }}</span>
                    <span class="text-[13px] text-mute">{{ $card['about'] }}</span>
                    @if ($card['status'])<span class="mt-2 text-[13px] font-semibold">{{ $card['status'] }}</span>@endif
                </a>@if ($card['presents'] ?? null)<x-presented-by :target="$card['presents']" class="px-1" />@endif</li>
            @endforeach
        </ul>

        @include('partials.game-day-and-contests')
    </div>
</x-layouts::stoop>
