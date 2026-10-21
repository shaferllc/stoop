<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>{{ filled($title ?? null) ? $title.' · Stoop' : 'Stoop' }}</title>
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="manifest" href="/manifest.webmanifest">
        <link rel="apple-touch-icon" href="/icons/icon-192.png">
        <meta name="theme-color" content="#c8102e">
        <meta name="description" content="{{ $description ?? 'Stoop is a social network for DC, block by block.' }}">
        <meta property="og:site_name" content="Stoop">
        <meta property="og:title" content="{{ $title ?? 'Stoop' }}">
        <meta property="og:description" content="{{ $description ?? 'A social network for DC, block by block.' }}">
        <meta property="og:url" content="{{ url()->current() }}">
        @isset($ogImage)
            <meta property="og:image" content="{{ $ogImage }}">
            <meta property="og:image:width" content="1200">
            <meta property="og:image:height" content="630">
        @endisset
        <meta name="twitter:card" content="{{ isset($ogImage) ? 'summary_large_image' : 'summary' }}">
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=newsreader:500,700|public-sans:400,500,600,700|ibm-plex-mono:500&display=swap" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
    </head>
    @php($me = auth()->user())
    <body class="min-h-screen bg-paper font-sans text-[15px] text-ink antialiased" data-profile="{{ $me ? route('users.show', $me, false) : '' }}">
        @php($unread = $me?->unreadNotifications()->count() ?? 0)

        <header class="sticky top-0 z-20 border-b border-rule bg-card">
            <div class="mx-auto flex max-w-6xl items-center gap-4 px-4 py-3">
                <a href="{{ route('home') }}" wire:navigate class="leading-none">
                    <span class="font-display text-[26px] font-bold tracking-tight">Stoop</span>
                    <span class="mt-0.5 block text-[9.5px] font-semibold uppercase tracking-[0.16em] text-mute">DC, block by block</span>
                </a>

                <form action="{{ route('search') }}" method="GET" class="hidden flex-1 sm:block sm:max-w-sm" role="search">
                    <label class="flex items-center gap-2 rounded-lg border border-rule bg-paper px-3 py-1.5 text-sm text-mute focus-within:border-ink">
                        <input name="q" id="site-search" value="{{ request('q') }}" placeholder="Search dispatches, people, stations" class="min-w-0 flex-1 bg-transparent text-ink outline-none" autocomplete="off">
                        <kbd class="rounded border border-rule px-1.5 font-mono text-[11px]">/</kbd>
                    </label>
                </form>

                <div class="ml-auto flex items-center gap-2">
                    @auth
                        <a href="{{ route('home') }}#compose" class="rounded-lg bg-flag px-4 py-2 text-sm font-semibold text-white">+ File a dispatch</a>
                        <flux:dropdown position="bottom" align="end">
                            <button type="button" class="grid size-9 place-items-center rounded-full text-xs font-bold text-white" style="background: {{ $me->color() }}" aria-label="Account menu">{{ $me->initials() }}</button>
                            <flux:menu>
                                <flux:menu.item :href="route('users.show', $me)" wire:navigate>Your profile</flux:menu.item>
                                <flux:menu.item :href="route('profile.edit')" wire:navigate>Settings</flux:menu.item>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <flux:menu.item as="button" type="submit">Log out</flux:menu.item>
                                </form>
                            </flux:menu>
                        </flux:dropdown>
                    @else
                        <a href="{{ route('login') }}" class="rounded-lg border border-rule px-3 py-2 text-sm font-medium">Log in</a>
                        <a href="{{ route('register') }}" class="rounded-lg bg-flag px-3 py-2 text-sm font-semibold text-white">Join</a>
                    @endauth
                </div>
            </div>
        </header>

        <div class="mx-auto grid max-w-6xl gap-6 px-4 py-5 lg:grid-cols-[180px_minmax(0,1fr)_260px]">
            <nav class="hidden text-sm lg:block" aria-label="Stoop">
                @php($navItem = 'flex items-center rounded-md px-2.5 py-1.5 text-mute hover:bg-chip hover:text-ink')
                <a href="{{ route('home') }}" wire:navigate class="{{ $navItem }} {{ request()->routeIs('home') && ! request('line') && ! request('stop') ? 'bg-chip text-ink' : '' }}">Front Stoop</a>
                <a href="{{ route('places') }}" wire:navigate class="{{ $navItem }} {{ request()->routeIs('places*') ? 'bg-chip text-ink' : '' }}">Places</a>
                @auth
                    <a href="{{ route('notifications') }}" wire:navigate class="{{ $navItem }} {{ request()->routeIs('notifications') ? 'bg-chip text-ink' : '' }}">
                        Notifications
                        @if ($unread)<span class="ml-auto rounded-full bg-flag px-1.5 text-[11px] font-bold text-white">{{ $unread }}</span>@endif
                    </a>
                    @php($unreadMessages = \App\Support\Messaging::unreadCount($me))
                    <a href="{{ route('messages') }}" wire:navigate class="{{ $navItem }} {{ request()->routeIs('messages*') ? 'bg-chip text-ink' : '' }}">
                        Messages
                        @if ($unreadMessages)<span class="ml-auto rounded-full bg-flag px-1.5 text-[11px] font-bold text-white">{{ $unreadMessages }}</span>@endif
                    </a>
                    <a href="{{ route('saved') }}" wire:navigate class="{{ $navItem }} {{ request()->routeIs('saved') ? 'bg-chip text-ink' : '' }}">Saved</a>
                    <a href="{{ route('users.show', $me) }}" wire:navigate class="{{ $navItem }}">Profile</a>
                    @if ($me->is_staff)
                        <a href="{{ route('moderation') }}" wire:navigate class="{{ $navItem }} {{ request()->routeIs('moderation') ? 'bg-chip text-ink' : '' }}">Moderation</a>
                        <a href="{{ route('operations') }}" wire:navigate class="{{ $navItem }} {{ request()->routeIs('operations') ? 'bg-chip text-ink' : '' }}">Operations</a>
                    @endif
                @endauth

                <div class="mx-2.5 mt-5 mb-1.5 text-[10.5px] font-semibold uppercase tracking-[0.13em] text-mute">Lines</div>
                @foreach (config('stoop.lines') as $key => $line)
                    <a href="{{ route('home', ['line' => $key]) }}" wire:navigate class="{{ $navItem }} {{ request('line') === $key ? 'bg-chip text-ink' : '' }}">
                        <span class="mr-2 size-2.5 rounded-full" style="background: {{ $line['color'] }}"></span>{{ $line['name'] }}
                    </a>
                @endforeach

                @if ($me?->stops)
                    <div class="mx-2.5 mt-5 mb-1.5 text-[10.5px] font-semibold uppercase tracking-[0.13em] text-mute">Your stops</div>
                    @foreach ($me->stops as $code)
                        <a href="{{ route('home', ['stop' => $code]) }}" wire:navigate class="{{ $navItem }} {{ request('stop') === $code ? 'bg-chip text-ink' : '' }}">{{ $code }} · {{ config("stoop.stations.$code") }}</a>
                    @endforeach
                @endif
            </nav>

            <main class="min-w-0">
                {{ $slot }}
            </main>

            <aside class="hidden space-y-3 lg:block">
                <x-sidebar />
                <div class="rounded-xl border border-rule bg-card p-3.5 text-[13px] text-mute">
                    <div class="mb-2 text-[10.5px] font-semibold uppercase tracking-[0.13em]">House rules</div>
                    <p class="mb-1.5"><b class="text-ink">No attacks on people for who they are.</b> Argue about places, not neighbors.</p>
                    <p class="mb-1.5"><b class="text-ink">No networking posts.</b> This isn't LinkedIn for lobbyists.</p>
                    <p class="mb-1.5"><b class="text-ink">No campaign spam.</b> Talk about the city, not the Hill.</p>
                    <a href="{{ route('rules') }}" wire:navigate class="underline">Full rules</a> ·
                    <a href="{{ route('transparency') }}" wire:navigate class="underline">Transparency</a> ·
                    <button type="button" onclick="document.getElementById('shortcuts').showModal()" class="underline">Keyboard shortcuts</button>
                </div>
            </aside>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        <dialog id="shortcuts" class="m-auto w-full max-w-sm rounded-xl border border-rule bg-card p-5 text-ink backdrop:bg-black/40">
            <h2 class="font-display text-xl font-bold">Keyboard shortcuts</h2>
            <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm">
                @foreach (['j / k' => 'Next / previous dispatch', 'o or Enter' => 'Open the selected dispatch', 'c' => 'Write a dispatch', '/' => 'Search', 'g then h' => 'Front Stoop', 'g then n' => 'Notifications', 'g then s' => 'Saved', 'g then p' => 'Your profile', '⌘ Enter' => 'Post from the composer', '?' => 'This list'] as $keys => $what)
                    <dt><kbd class="rounded border border-rule px-1.5 font-mono text-xs">{{ $keys }}</kbd></dt><dd class="text-mute">{{ $what }}</dd>
                @endforeach
            </dl>
            <form method="dialog" class="mt-4 text-right"><button class="rounded-lg border border-rule px-3 py-1.5 text-sm">Close</button></form>
        </dialog>
        @fluxScripts
        <script>
            if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js').catch(() => {});
        </script>
    </body>
</html>
