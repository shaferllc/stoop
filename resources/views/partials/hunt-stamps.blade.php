@php($stamps = \App\Support\Passports::huntStamps(auth()->user()))
<section class="mt-4 rounded-xl border border-rule bg-card p-5">
    <div class="flex items-center gap-3">
        <h2 class="font-display text-xl font-bold">Scavenger hunt stamps</h2>
        <a href="{{ route('hunts') }}" wire:navigate class="ml-auto text-sm text-mute underline">See the hunts</a>
    </div>
    @if ($stamps->isEmpty())
        <p class="mt-1 text-sm text-mute">Every clue you crack in a scavenger hunt stamps your passport.</p>
    @else
        <div class="mt-3 flex flex-wrap gap-2">
            @foreach ($stamps as $stamp)
                <a href="{{ route('hunts.show', $stamp->slug) }}" wire:navigate class="rotate-[-4deg] rounded-md border-2 border-flag px-2 py-0.5 font-mono text-[12px] font-bold uppercase text-flag">{{ $stamp->hunt }} · {{ $stamp->position }} · {{ \Illuminate\Support\Carbon::parse($stamp->stamped_at)->format('M j') }}</a>
            @endforeach
        </div>
    @endif
</section>
