<div>
    <h1 class="mb-3 font-display text-3xl font-bold">Messages</h1>

    <form wire:submit="start" class="rounded-xl border border-rule bg-card p-3.5">
        <label for="msg-to" class="text-sm font-medium">New message</label>
        <div class="mt-1.5 flex flex-wrap gap-2">
            <input id="msg-to" wire:model="to" placeholder="Usernames, like mayaeats or mayaeats, jt_hill" class="min-w-0 flex-1 rounded-md border border-rule bg-paper px-2.5 py-1.5 text-sm">
            <input wire:model="groupName" maxlength="60" placeholder="Group name (optional)" aria-label="Group name" class="rounded-md border border-rule bg-paper px-2.5 py-1.5 text-sm">
            <button type="submit" class="rounded-lg bg-flag px-3.5 py-1.5 text-sm font-semibold text-white">Start</button>
        </div>
        @error('to') <p class="mt-1 text-sm text-flag">{{ $message }}</p> @enderror
        <p class="mt-1.5 text-[12.5px] text-mute">You can message people who follow you. Add more than one person, or a name, to start a group.</p>
    </form>

    <div class="mt-4 mb-3 flex gap-0.5 border-b border-rule">
        @foreach (['inbox' => 'Inbox', 'requests' => 'Requests'.($requestCount ? " ($requestCount)" : ''), 'archived' => 'Archived'] as $key => $label)
            <button type="button" wire:click="$set('box', '{{ $key }}')" @class(['border-b-2 px-3.5 py-2 text-sm font-medium', 'border-flag' => $box === $key, 'border-transparent text-mute' => $box !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    <ul class="divide-y divide-rule rounded-xl border border-rule bg-card" wire:poll.15s.visible>
        @forelse ($conversations as $c)
            @php
                $me = $c->pivotFor(auth()->user());
                $last = $latest[$c->id] ?? null;
                $unread = $c->last_message_at && (! $me->last_read_at || $c->last_message_at->gt($me->last_read_at));
            @endphp
            <li wire:key="c-{{ $c->id }}">
                <a href="{{ route('messages.show', $c) }}" wire:navigate class="flex items-center gap-3 px-4 py-3 hover:bg-chip/50">
                    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-ink text-xs font-bold text-paper">{{ $c->is_group ? $c->participants->count() : str($c->titleFor(auth()->user()))->substr(0, 1) }}</span>
                    <span class="min-w-0 flex-1">
                        <span @class(['block truncate', 'font-bold' => $unread, 'font-semibold' => ! $unread])>{{ $c->titleFor(auth()->user()) }}</span>
                        <span class="block truncate text-[13px] text-mute">{{ $last ? ($last->user_id === auth()->id() ? 'You: ' : '').str($last->body ?: 'Photo')->limit(70) : 'No messages yet' }}</span>
                    </span>
                    @if ($c->last_message_at)<span class="text-[12px] text-mute">{{ $c->last_message_at->shortAbsoluteDiffForHumans() }}</span>@endif
                    @if ($unread)<span class="size-2 rounded-full bg-flag" aria-label="Unread"></span>@endif
                </a>
            </li>
        @empty
            <li class="p-8 text-center text-mute">{{ $box === 'requests' ? 'No message requests.' : ($box === 'archived' ? 'Nothing archived.' : 'No conversations yet.') }}</li>
        @endforelse
    </ul>
</div>
