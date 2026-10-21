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
                        'best' => 'picked your reply as the Best Answer',
                        'follow' => 'followed you',
                        'moderation' => $data['message'] ?? 'A moderator reviewed your dispatch',
<<<<<<< HEAD
                        'author' => 'posted a new story by '.($data['author'] ?? 'a writer you follow'),
                        'quote_request' => 'asked to quote your dispatch in a '.config('stoop.magazine_name').' story',
=======
                        'welcome' => 'says welcome to Stoop',
>>>>>>> worktree-agent-a4ea324e96877cacd
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
                @elseif ($group['kind'] === 'welcome')
                    <ul class="mt-1 list-disc pl-5 text-mute">
                        @foreach ($data['tips'] ?? [] as $tip)<li>{{ $tip }}</li>@endforeach
                    </ul>
                    @if ($data['people'] ?? [])
                        <div class="mt-1.5 flex flex-wrap gap-2">
                            @foreach ($data['people'] as $username)
                                <a href="{{ url('@'.$username) }}" wire:navigate class="rounded-full border border-rule px-2.5 py-0.5 text-[12.5px] font-semibold">Follow {{ '@'.$username }}</a>
                            @endforeach
                        </div>
                    @endif
                @elseif ($data['dispatch_id'] ?? null)
                    <a href="{{ route('dispatches.show', $data['dispatch_id']) }}" wire:navigate class="mt-1 block text-mute hover:text-ink">{{ $data['excerpt'] }}</a>
                @endif

                @if ($group['kind'] === 'quote_request' && ($request = \App\Models\QuoteRequest::find($data['quote_request_id'] ?? 0)))
                    <div class="mt-1.5 flex flex-wrap items-center gap-2 text-[12.5px]">
                        @if ($request->state() === 'pending')
                            <span class="text-mute">Your name and words would appear in the story. Expires {{ $request->expires_at->toFormattedDateString() }}.</span>
                            <form method="POST" action="{{ route('quote-requests.answer', [$request, 'allow']) }}">@csrf<button class="rounded-full bg-flag px-2.5 py-0.5 font-semibold text-white">Allow</button></form>
                            <form method="POST" action="{{ route('quote-requests.answer', [$request, 'decline']) }}">@csrf<button class="rounded-full border border-rule px-2.5 py-0.5 font-semibold">Decline</button></form>
                        @elseif ($request->state() === 'allowed')
                            <span class="text-mute">You allowed this.</span>
                            <form method="POST" action="{{ route('quote-requests.answer', [$request, 'decline']) }}">@csrf<button class="rounded-full border border-rule px-2.5 py-0.5 font-semibold">Withdraw permission</button></form>
                        @else
                            <span class="text-mute">{{ $request->state() === 'expired' ? 'This request expired.' : 'You declined.' }}</span>
                        @endif
                    </div>
                @endif
            </li>
        @empty
            <li><x-empty title="All quiet" class="border-0">Replies, mentions and new followers show up here.</x-empty></li>
        @endforelse
    </ul>
</div>
