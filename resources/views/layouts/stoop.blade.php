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
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="vapid-key" content="{{ config('services.webpush.public_key') }}">
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
    <body class="min-h-screen bg-paper pb-[calc(4rem+env(safe-area-inset-bottom))] font-sans text-[15px] text-ink antialiased lg:pb-0" data-profile="{{ $me ? route('users.show', $me, false) : '' }}">

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
                        <button type="button" x-data x-on:click="$dispatch('open-composer')" class="hidden rounded-lg bg-flag px-4 py-2 text-sm font-semibold text-white sm:block">+ File a dispatch</button>
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
                        <livewire:unread-badge type="notifications" />
                    </a>
                    <a href="{{ route('messages') }}" wire:navigate class="{{ $navItem }} {{ request()->routeIs('messages*') ? 'bg-chip text-ink' : '' }}">
                        Messages
                        <livewire:unread-badge type="messages" />
                    </a>
                    <a href="{{ route('saved') }}" wire:navigate class="{{ $navItem }} {{ request()->routeIs('saved') ? 'bg-chip text-ink' : '' }}">Saved</a>
                    <a href="{{ route('users.show', $me) }}" wire:navigate class="{{ $navItem }}">Profile</a>
                    @if ($me->is_staff)
                        <a href="{{ route('moderation') }}" wire:navigate class="{{ $navItem }} {{ request()->routeIs('moderation') ? 'bg-chip text-ink' : '' }}">Moderation</a>
                        <a href="{{ route('admin') }}" wire:navigate class="{{ $navItem }} {{ request()->routeIs('admin*') ? 'bg-chip text-ink' : '' }}">Admin</a>
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
                    <a href="{{ route('privacy') }}" wire:navigate class="underline">Privacy</a> ·
                    <a href="{{ route('terms') }}" wire:navigate class="underline">Terms</a> ·
                    <button type="button" onclick="document.getElementById('shortcuts').showModal()" class="underline">Keyboard shortcuts</button>
                </div>
            </aside>
        </div>

        {{-- Phones: a bottom tab bar, and the composer as a sheet that slides up. --}}
        <nav class="fixed inset-x-0 bottom-0 z-30 border-t border-rule bg-card pb-[env(safe-area-inset-bottom)] lg:hidden" aria-label="Stoop">
            <div class="mx-auto grid max-w-md grid-cols-5 text-[11px]">
                @php($tab = 'flex flex-col items-center gap-0.5 py-2 text-mute')
                <a href="{{ route('home') }}" wire:navigate @class([$tab, 'text-ink' => request()->routeIs('home')])><flux:icon.home variant="mini" />Stoop</a>
                <a href="{{ route('search') }}" wire:navigate @class([$tab, 'text-ink' => request()->routeIs('search')])><flux:icon.magnifying-glass variant="mini" />Search</a>
                @auth
                    <button type="button" x-data x-on:click="$dispatch('open-composer')" class="{{ $tab }}"><span class="-mt-5 grid size-11 place-items-center rounded-full bg-flag text-white shadow-lg"><flux:icon.plus /></span><span class="sr-only">File a dispatch</span></button>
                    <a href="{{ route('messages') }}" wire:navigate @class([$tab, 'relative', 'text-ink' => request()->routeIs('messages*')])><flux:icon.chat-bubble-left-right variant="mini" />Messages</a>
                    <a href="{{ route('notifications') }}" wire:navigate @class([$tab, 'relative', 'text-ink' => request()->routeIs('notifications')])><flux:icon.bell variant="mini" />Alerts<span class="absolute right-3 top-1"><livewire:unread-badge type="notifications" :key="'tab-badge'" /></span></a>
                @else
                    <a href="{{ route('places') }}" wire:navigate class="{{ $tab }}"><flux:icon.map-pin variant="mini" />Places</a>
                    <a href="{{ route('login') }}" class="{{ $tab }}"><flux:icon.arrow-right-end-on-rectangle variant="mini" />Log in</a>
                    <a href="{{ route('register') }}" class="{{ $tab }}"><flux:icon.user-plus variant="mini" />Join</a>
                @endauth
            </div>
        </nav>

        @auth
            <div x-data="{ open: false }" x-on:open-composer.window="open = true; $nextTick(() => $el.querySelector('[data-composer]')?.focus())"
                x-on:dispatch-filed.window="open = false" x-on:keydown.escape.window="open = false" x-cloak>
                <div x-show="open" x-transition.opacity class="fixed inset-0 z-40 bg-black/40" x-on:click="open = false"></div>
                <div x-show="open" x-transition:enter="transition motion-reduce:transition-none" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
                    class="fixed inset-x-0 bottom-0 z-50 max-h-[90vh] overflow-y-auto rounded-t-2xl bg-paper p-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))]" role="dialog" aria-label="File a dispatch">
                    <div class="mb-2 flex items-center justify-between">
                        <span class="font-display text-lg font-bold">File a dispatch</span>
                        <button type="button" x-on:click="open = false" class="text-2xl leading-none text-mute" aria-label="Close">×</button>
                    </div>
                    <livewire:composer :key="'sheet-composer'" />
                </div>
            </div>
        @endauth

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
