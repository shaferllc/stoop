<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Business')" :subheading="__('Your plan, billing, places and promotions')">
        <div class="my-6 space-y-8 text-sm">
            @if ($checkout === 'done')
                <p class="rounded-lg bg-chip p-3">Thanks! Your Business plan turns on as soon as Stripe confirms the payment, usually within a minute.</p>
            @endif
            @if ($promoted !== '')
                <p class="rounded-lg bg-chip p-3">Payment received. Our team reviews every promotion before it runs; you’ll get a notification either way, and a refund if it isn’t approved.</p>
            @endif

            <div>
                <flux:heading>{{ __('Plan') }}: {{ $plan['name'] }}</flux:heading>
                <ul class="mt-2 list-disc pl-5 text-mute">
                    <li>Up to {{ config('business.plans.business.deals') }} deals running at once (Free: {{ config('business.plans.free.deals') }})</li>
                    <li>{{ config('business.plans.business.free_promotions') }} free promoted happening each month</li>
                    <li>Analytics: page views, deal views and clicks, check-ins and Been heres</li>
                    <li>Several locations under one account</li>
                    <li>A “Verified business” badge on your places and the posts you make as them</li>
                </ul>
                @if ($isBusiness)
                    <p class="mt-2">
                        Business · {{ $account->status === 'past_due' ? 'payment overdue, please update your card' : ($account->cancel_at_period_end ? 'ends' : 'renews') }}
                        @if ($account->current_period_end) {{ $account->current_period_end->timezone(config('stoop.timezone'))->format('M j, Y') }} @endif
                    </p>
                @endif
                <div class="mt-3 flex flex-wrap gap-2">
                    @if (! $isBusiness && $canUpgrade && $places->isNotEmpty())
                        <form method="POST" action="{{ route('business.upgrade') }}">@csrf<button class="rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Upgrade to Business · ${{ config('business.plans.business.price_cents') / 100 }}/mo</button></form>
                    @endif
                    @if ($stripe && $account?->stripe_customer_id)
                        <form method="POST" action="{{ route('business.billing') }}">@csrf<button class="rounded-lg border border-rule px-3 py-1.5">Manage billing or cancel</button></form>
                    @endif
                </div>
                @if ($places->isEmpty())
                    <p class="mt-2 text-mute">Business is for places you manage. Find your place on <a href="{{ route('places') }}" wire:navigate class="underline">Places</a> and tap “Own this place?” first.</p>
                @endif
            </div>

            @if ($places->isNotEmpty())
                <div>
                    <flux:heading>{{ __('Your places') }}</flux:heading>
                    <ul class="mt-2 divide-y divide-rule">
                        @foreach ($places as $place)
                            <li class="flex items-center justify-between py-2">
                                <a href="{{ route('places.show', $place) }}" wire:navigate class="font-semibold hover:underline">{{ $place->name }}</a>
                                @if ($isBusiness)<a href="{{ route('business.analytics', $place) }}" wire:navigate class="underline">Analytics</a>@endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($promotions->isNotEmpty())
                <div>
                    <flux:heading>{{ __('Promotions') }}</flux:heading>
                    <div class="mt-2">@include('partials.promotion-rows')</div>
                </div>
            @endif
        </div>
    </x-settings.layout>
</section>
