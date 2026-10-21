{{-- A Happening can be a watch party for an upcoming DC game; it then shows on the game's page and thread. --}}
@php($watchGames = \App\Support\Sports::upcoming())
@if ($watchGames->isNotEmpty())
    <select wire:model="watchGameId" aria-label="Watch party" class="mt-2 w-full rounded-md border border-rule bg-paper px-2.5 py-1.5 text-sm">
        <option value="">Watch party? Pick the game (optional)</option>
        @foreach ($watchGames as $g)
            <option value="{{ $g->id }}">{{ $g->matchup() }} · {{ $g->when() }}</option>
        @endforeach
    </select>
@endif
