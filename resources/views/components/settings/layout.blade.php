{{-- Settings pages inside the Stoop layout: tabs across the top, the page in a card. --}}
<div>
    <nav class="mb-4 mt-3 flex gap-1 overflow-x-auto border-b border-rule text-sm" aria-label="{{ __('Settings') }}">
        @foreach (['profile.edit' => __('Profile'), 'safety.edit' => __('Safety'), 'tokens.edit' => __('Terminal & API'), 'security.edit' => __('Security'), 'appearance.edit' => __('Appearance')] as $route => $label)
            <a href="{{ route($route) }}" wire:navigate @class(['whitespace-nowrap border-b-2 px-3 py-2 font-medium', 'border-flag text-ink' => request()->routeIs($route), 'border-transparent text-mute hover:text-ink' => ! request()->routeIs($route)])>{{ $label }}</a>
        @endforeach
    </nav>

    <section class="rounded-xl border border-rule bg-card p-5 sm:p-6">
        @if ($heading ?? null)<h2 class="font-display text-xl font-bold">{{ $heading }}</h2>@endif
        @if ($subheading ?? null)<p class="text-sm text-mute">{{ $subheading }}</p>@endif

        <div class="mt-4 w-full max-w-xl">
            {{ $slot }}
        </div>
    </section>
</div>
