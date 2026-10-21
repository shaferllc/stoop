<div>
    <h1 class="mb-3 font-display text-3xl font-bold">Notifications</h1>

    <div class="mb-3 flex gap-1.5 overflow-x-auto">
        @foreach (\App\Livewire\Pages\Notifications::FILTERS as $key => $label)
            <button type="button" wire:click="$set('filter', '{{ $key }}')" @class(['shrink-0 rounded-full border px-3 py-0.5 text-[13px]', 'border-ink text-ink' => $filter === $key, 'border-transparent bg-chip text-mute' => $filter !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    <ul class="divide-y divide-rule rounded-xl border border-rule bg-card">
        @forelse ($groups as $key => $group)
            @php
                $data = $group['latest']->data;
                $names = $group['actors'];
                $names = $names->filter()->values();
                $who = $names->isEmpty() ? 'Stoop moderators' : '@'.$names->first().($names->count() > 1 ? ' and '.($names->count() - 1).' '.str('other')->plural($names->count() - 1) : '');
            @endphp
            <li wire:key="n-{{ $key }}" @class(['px-4 py-3 text-sm', 'bg-chip/50' => $group['unread']])>
                <div class="flex flex-wrap items-center gap-x-1.5">
                    @if ($names->isEmpty())
                        <b>{{ $who }}</b>
                    @else
                        <a href="{{ route('users.show', $names->first()) }}" wire:navigate class="font-semibold">{{ $who }}</a>
                    @endif
                    <span>{{ match ($group['kind']) {
                        'reply' => $names->count() > 1 ? 'replied in your thread' : 'replied to your dispatch',
                        'quote' => 'quoted your dispatch',
                        'mention' => 'mentioned you',
                        'follow' => 'followed you',
                        'moderation' => $data['message'] ?? 'A moderator reviewed your dispatch',
                        default => 'did something',
                    } }}</span>
                    <span class="text-mute">· {{ $group['latest']->created_at->shortAbsoluteDiffForHumans() }}</span>
                </div>

                @if ($group['kind'] === 'follow')
                    <div class="mt-1.5 flex flex-wrap gap-2">
                        @foreach ($group['items'] as $n)
                            @php($id = $n->data['actor_id'])
                            @if (! in_array($id, $followingBack) && isset($actorsById[$id]))
                                <button type="button" wire:click="toggleFollow({{ $id }})" class="rounded-full border border-rule px-2.5 py-0.5 text-[12.5px] font-semibold">Follow back {{ '@'.$n->data['actor'] }}</button>
                            @endif
                        @endforeach
                    </div>
                @elseif ($data['dispatch_id'] ?? null)
                    <a href="{{ route('dispatches.show', $data['dispatch_id']) }}" wire:navigate class="mt-1 block text-mute hover:text-ink">{{ $data['excerpt'] }}</a>
                @endif
            </li>
        @empty
            <li><x-empty title="All quiet" class="border-0">Replies, mentions and new followers show up here.</x-empty></li>
        @endforelse
    </ul>
</div>
