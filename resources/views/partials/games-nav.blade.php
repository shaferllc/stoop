<nav class="flex gap-1 overflow-x-auto border-b border-rule text-sm" aria-label="Games">
    @foreach (['bingo' => 'Metro Bingo', 'hunts' => 'Scavenger hunts', 'line-cup' => 'Line Cup', 'rivalries' => 'Rivalries', 'quiz' => 'The Daily'] as $route => $label)
        <a href="{{ route($route) }}" wire:navigate @class(['whitespace-nowrap border-b-2 px-3 py-2 font-medium', 'border-flag text-ink' => request()->routeIs($route, $route.'.*'), 'border-transparent text-mute hover:text-ink' => ! request()->routeIs($route, $route.'.*')])>{{ $label }}</a>
    @endforeach
</nav>
