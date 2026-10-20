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

        @if ($this->showDeleteUser)
            <livewire:settings.delete-user-form />
        @endif
    </x-settings.layout>
</section>
