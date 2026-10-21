<nav class="mb-4 flex gap-1 overflow-x-auto border-b border-rule text-sm" aria-label="Admin">
    @php($adminTabs = [
        'admin' => 'Overview',
        'admin.activity' => 'Activity',
        'admin.users' => 'People',
        'admin.places' => 'Places',
        'admin.sponsorships' => 'Sponsorships',
        'admin.ads' => 'Ads',
        'admin.content' => 'Content',
        'admin.magazine' => 'Magazine',
        'admin.editorial' => 'Editorial',
        'admin.anc' => 'ANC',
        'admin.tips' => 'Tips',
        'admin.radar' => 'Radar',
        'admin.callouts' => 'Call-outs',
        'admin.archive' => 'Archive',
        'admin.issues' => 'Issues',
        'admin.history' => 'History',
        'admin.quiz' => 'Quiz',
        'admin.moments' => 'Moments',
        'admin.hunts' => 'Hunts',
        'admin.readers-picks' => 'Readers’ picks',
        'admin.challenges' => 'Challenges',
        'admin.magazine-events' => 'Magazine events',
        'moderation' => 'Moderation',
        'admin.moderation-insights' => 'Mod insights',
        'admin.spam' => 'Spam',
        'admin.bots' => 'Bots',
        'admin.roles' => 'Roles',
        'admin.real-estate' => 'Real estate',
        'admin.insights' => 'Insights',
        'admin.referrals' => 'Referrals',
        'admin.areas' => 'Area reports',
        'admin.listings' => 'Classifieds',
        'admin.audit' => 'Audit log',
    ])
    @foreach ($adminTabs as $route => $label)
        @continue(! auth()->user()->hasRole(\App\Support\Roles::PAGES[$route] ?? null))
        <a href="{{ route($route) }}" wire:navigate @class(['whitespace-nowrap border-b-2 px-3 py-2 font-medium', 'border-flag text-ink' => request()->routeIs($route), 'border-transparent text-mute hover:text-ink' => ! request()->routeIs($route)])>{{ $label }}</a>
    @endforeach
</nav>
