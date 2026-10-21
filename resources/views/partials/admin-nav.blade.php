<nav class="mb-4 flex gap-1 overflow-x-auto border-b border-rule text-sm" aria-label="Admin">
    @foreach (['admin' => 'Overview', 'admin.users' => 'People', 'admin.places' => 'Places', 'admin.sponsorships' => 'Sponsorships', 'admin.content' => 'Content', 'admin.quiz' => 'Quiz', 'admin.moments' => 'Moments', 'moderation' => 'Moderation', 'admin.audit' => 'Audit log'] as $route => $label)
        <a href="{{ route($route) }}" wire:navigate @class(['whitespace-nowrap border-b-2 px-3 py-2 font-medium', 'border-flag text-ink' => request()->routeIs($route), 'border-transparent text-mute hover:text-ink' => ! request()->routeIs($route)])>{{ $label }}</a>
    @endforeach
    {{-- Newsroom tools --}}
    @foreach (['admin.tips' => 'Tips', 'admin.radar' => 'Radar', 'admin.callouts' => 'Call-outs', 'admin.archive' => 'Archive', 'admin.issues' => 'Issues'] as $route => $label)
        <a href="{{ route($route) }}" wire:navigate @class(['whitespace-nowrap border-b-2 px-3 py-2 font-medium', 'border-flag text-ink' => request()->routeIs($route), 'border-transparent text-mute hover:text-ink' => ! request()->routeIs($route)])>{{ $label }}</a>
    @endforeach
</nav>
