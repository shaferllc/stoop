<nav class="flex gap-1 overflow-x-auto border-b border-rule text-sm" aria-label="Games">
    @foreach (['play' => 'All games', 'sports' => 'Sports', 'quiz' => 'The Daily', 'bingo' => 'Metro Bingo', 'hunts' => 'Scavenger hunts', 'passports' => 'Passports', 'rivalries' => 'Rivalries & Line Cup'] as $route => $label)
        <a href="{{ route($route) }}" wire:navigate @class(['whitespace-nowrap border-b-2 px-3 py-2 font-medium', 'border-flag text-ink' => request()->routeIs($route, $route.'.*') || ($route === 'rivalries' && request()->routeIs('line-cup')) || ($route === 'play' && request()->routeIs('contests.*')), 'border-transparent text-mute hover:text-ink' => ! request()->routeIs($route, $route.'.*') || ($route === 'rivalries' && request()->routeIs('line-cup')) || ($route === 'play' && request()->routeIs('contests.*'))])>{{ $label }}</a>
    @endforeach
</nav>
