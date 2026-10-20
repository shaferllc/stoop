<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Safety')" :subheading="__('Who can reply to you, and the people you’ve muted or blocked')">
        <div class="my-6 space-y-8">
            <flux:radio.group wire:model.live="repliesFrom" :label="__('Who can reply to your dispatches')">
                <flux:radio value="everyone" :label="__('Everyone')" />
                <flux:radio value="following" :label="__('Only people you follow')" />
            </flux:radio.group>

            <div>
                <flux:heading>{{ __('Muted and blocked') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Muted people can’t see that you muted them. Blocked people can’t see your posts or reply to you.') }}</flux:text>
                <ul class="mt-3 divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($silenced as $person)
                        <li class="flex items-center justify-between py-2 text-sm">
                            <span><b>{{ $person->name }}</b> {{ '@'.$person->username }} · {{ $person->pivot->type === 'block' ? 'Blocked' : 'Muted' }}</span>
                            <flux:button size="sm" wire:click="undo({{ $person->id }}, '{{ $person->pivot->type }}')">{{ $person->pivot->type === 'block' ? __('Unblock') : __('Unmute') }}</flux:button>
                        </li>
                    @empty
                        <li class="py-2 text-sm text-zinc-500">{{ __('You haven’t muted or blocked anyone.') }}</li>
                    @endforelse
                </ul>
            </div>

            @if ($penalties->isNotEmpty())
                <div>
                    <flux:heading>{{ __('Your account history') }}</flux:heading>
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($penalties as $p)
                            <li>
                                <b>{{ $p->label() }}</b> · {{ $p->reason }} · {{ $p->created_at->toFormattedDateString() }}
                                @if ($p->lifted_at) (lifted) @elseif ($p->type !== 'warning')
                                    · <a href="{{ route('appeals.create', ['penalty' => $p->id]) }}" class="underline" wire:navigate>{{ __('Appeal') }}</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </x-settings.layout>
</section>
