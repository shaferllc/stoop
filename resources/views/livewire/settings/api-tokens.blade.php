<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Terminal & API')" :subheading="__('Post from your terminal or your own scripts')">
        <div class="my-6 space-y-6">
            <form wire:submit="create" class="flex items-end gap-2">
                <flux:input wire:model="name" :label="__('New token name')" placeholder="Laptop terminal" class="flex-1" />
                <flux:button type="submit" variant="primary">{{ __('Create token') }}</flux:button>
            </form>

            @if ($plainToken)
                <div class="rounded-lg border border-zinc-300 p-3 text-sm dark:border-zinc-600">
                    <p class="font-semibold">{{ __('Copy this token now. You won’t see it again.') }}</p>
                    <code class="mt-1 block break-all font-mono">{{ $plainToken }}</code>
                </div>
            @endif

            <div class="text-sm">
                <flux:heading>{{ __('Post from a terminal') }}</flux:heading>
                <pre class="mt-2 overflow-x-auto rounded-lg bg-zinc-100 p-3 font-mono text-xs dark:bg-zinc-800">curl -fsSL {{ route('terminal') }} | STOOP_TOKEN=your-token bash -s -- "Red Line is delayed at Dupont" --line red --stop A03</pre>
                <p class="mt-2 text-zinc-500">{{ __('Or call the API directly: POST') }} <code>{{ url('/api/dispatches') }}</code> {{ __('with a Bearer token and a JSON body like') }} <code>{"body": "…", "line": "red", "stop": "A03"}</code>. {{ __('The same rules and limits apply as on the website.') }}</p>
                <p class="mt-2 text-zinc-500">{{ __('Feeds:') }} <code>{{ route('feeds.latest') }}</code>, <code>{{ route('feeds.line', 'red') }}</code>, <code>{{ route('feeds.stop', 'E04') }}</code></p>
            </div>

            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($tokens as $token)
                    <li class="flex items-center justify-between py-2 text-sm">
                        <span><b>{{ $token->name }}</b> · {{ __('created') }} {{ $token->created_at->toFormattedDateString() }} · {{ $token->last_used_at ? __('last used').' '.$token->last_used_at->diffForHumans() : __('never used') }}</span>
                        <flux:button size="sm" wire:click="revoke({{ $token->id }})">{{ __('Revoke') }}</flux:button>
                    </li>
                @empty
                    <li class="py-2 text-sm text-zinc-500">{{ __('No tokens yet.') }}</li>
                @endforelse
            </ul>
        </div>
    </x-settings.layout>
</section>
