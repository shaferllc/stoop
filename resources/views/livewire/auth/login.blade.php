<x-layouts::auth :title="__('Log in')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Log in to your account')" :description="__('Enter your email and password below to log in')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <x-passkey-verify />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
            />

            <!-- Password -->
            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Password')"
                    viewable
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute top-0 text-sm end-0" :href="route('password.request')" wire:navigate>
                        {{ __('Forgot your password?') }}
                    </flux:link>
                @endif
            </div>

            <!-- Remember Me -->
            <flux:checkbox name="remember" :label="__('Remember me')" :checked="old('remember')" />

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                    {{ __('Log in') }}
                </flux:button>
            </div>
        </form>

        {{-- Local development only, and only on the .test address, never through a public tunnel. --}}
        @if (app()->isLocal() && Route::has('dev.login') && ! request()->headers->has('X-Forwarded-For') && ! request()->headers->has('X-Forwarded-Host') && (str_ends_with(request()->getHost(), '.test') || in_array(request()->getHost(), ['localhost', '127.0.0.1'], true)))
            <div class="rounded-lg border border-dashed border-zinc-300 p-3 text-sm dark:border-zinc-600">
                <p class="font-semibold">Quick login (local only)</p>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    @foreach (\App\Models\User::whereIn('username', ['tshafer', 'admin_app', 'margot_ellison', 'renata_okafor', 'calvin_brooks', 'dre_w13', 'jasper_cole', 'you'])->get() as $u)
                        <a href="{{ URL::temporarySignedRoute('dev.login', now()->addMinutes(10), ['user' => $u], absolute: false) }}" class="rounded-md border border-zinc-300 px-2 py-1 hover:bg-zinc-100 dark:border-zinc-600 dark:hover:bg-zinc-800">{{ '@'.$u->username }}</a>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="space-x-1 text-sm text-center rtl:space-x-reverse text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Don\'t have an account?') }}</span>
            <flux:link :href="route('register')" wire:navigate>{{ __('Sign up') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
