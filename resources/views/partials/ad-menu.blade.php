{{-- "Why am I seeing this?", Hide and Report for an ad card. Needs $kind, $id and $why. --}}
<details class="relative shrink-0 text-[12px] font-normal normal-case tracking-normal text-mute">
    <summary class="cursor-pointer list-none hover:text-ink">Why am I seeing this?</summary>
    <div class="absolute right-0 z-20 mt-1 w-64 space-y-2 rounded-lg border border-rule bg-card p-3 text-[13px] text-ink shadow-lg">
        <p>{{ $why }}</p>
        <p class="text-mute">Ads on Stoop are picked by the stop or topic you’re looking at, never by who you are or what you do here. They never count toward rankings, points, mayors or rivalries.</p>
        <button type="button" wire:click="hideAd('{{ $kind }}', {{ $id }})" class="underline">Hide this ad</button>
        @auth
            <div class="text-mute">Report it:
                @foreach (\App\Support\Ads::REPORT_REASONS as $reason => $label)
                    <button type="button" wire:click="hideAd('{{ $kind }}', {{ $id }}, '{{ $reason }}')" class="underline hover:text-ink">{{ $label }}</button>{{ $loop->last ? '' : ' ·' }}
                @endforeach
            </div>
        @endauth
    </div>
</details>
