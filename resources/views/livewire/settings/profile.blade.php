<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-settings.layout :heading="__('Profile')" :subheading="__('Update your name, username and where you are in DC')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <flux:input wire:model="username" :label="__('Username')" type="text" required autocomplete="username" />

            <flux:checkbox wire:model.live="is_native" :label="__('I grew up in the DC area')" />

            @unless ($is_native)
                <flux:input wire:model="dc_since" :label="__('Year you moved here')" type="number" placeholder="2019" />
            @endunless

            <flux:select wire:model="translation_language" :label="__('Translate posts into')">
                @foreach (\App\Support\Translator::LANGUAGES as $code => $language)
                    <flux:select.option :value="$code">{{ $language }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:checkbox wire:model="newsletter" :label="__('Weekly email')" :description="__('The week’s best dispatches, poll results and where people went. Mondays.')" />

            <flux:checkbox wire:model="half_smokes_on" :label="__('Half-smokes')" :description="__('Get 10 a day to hand out to dispatches you love. Just for fun; they don’t affect rankings.')" />

            <flux:checkbox.group wire:model="stops" :label="__('Your stops (up to 3)')" :description="__('Near me shows dispatches tagged with these stations.')">
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    @foreach (config('stoop.stations') as $code => $station)
                        <flux:checkbox :value="$code" :label="$code.' · '.$station" />
                    @endforeach
                </div>
            </flux:checkbox.group>

            <div>
                <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

                @if ($this->hasUnverifiedEmail)
                    <div>
                        <flux:text class="mt-4">
                            {{ __('Your email address is unverified.') }}

                            <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                {{ __('Click here to re-send the verification email.') }}
                            </flux:link>
                        </flux:text>

                    </div>
                @endif
            </div>

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </div>
        </form>

        <div class="my-8" x-data="{ state: 'checking' }" x-init="state = window.stoopPush ? await window.stoopPush.status() : 'unsupported'">
            <flux:heading>{{ __('Push notifications') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Get replies, mentions and messages on this device, even when Stoop is closed. On iPhone, add Stoop to your Home Screen first.') }}</flux:text>
            <div class="mt-3 text-sm">
                <template x-if="state === 'off'"><flux:button x-on:click="state = await window.stoopPush.enable()">{{ __('Turn on for this device') }}</flux:button></template>
                <template x-if="state === 'on'"><flux:button x-on:click="state = await window.stoopPush.disable()">{{ __('Turn off for this device') }}</flux:button></template>
                <p x-show="state === 'blocked'" x-cloak class="text-zinc-500">{{ __('Notifications are blocked for Stoop in your browser settings.') }}</p>
                <p x-show="state === 'unsupported'" x-cloak class="text-zinc-500">{{ __('This browser doesn’t support push notifications.') }}</p>
            </div>
        </div>

        <div class="my-8">
            <flux:heading>{{ __('Your data') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Download everything Stoop stores about you as a JSON file. Deleting your account below erases it, except that any timeout or ban stays in force.') }}</flux:text>
            <flux:button class="mt-3" :href="route('export')">{{ __('Download my data') }}</flux:button>
        </div>

        @if ($this->showDeleteUser)
            <livewire:settings.delete-user-form />
        @endif
    </x-settings.layout>
</section>
