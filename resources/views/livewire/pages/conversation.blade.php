<div>
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('messages') }}" wire:navigate class="text-sm text-mute hover:text-ink">← Messages</a>
        <h1 class="font-display text-2xl font-bold">{{ $conversation->titleFor(auth()->user()) }}</h1>
        <div class="ml-auto flex gap-2 text-sm">
            @if ($pivot->accepted_at)
                <button type="button" wire:click="toggleArchive" class="rounded-lg border border-rule px-2.5 py-1">{{ $pivot->archived_at ? 'Unarchive' : 'Archive' }}</button>
            @endif
            @if ($conversation->is_group)
                <button type="button" wire:click="leave" wire:confirm="Leave this group?" class="rounded-lg border border-rule px-2.5 py-1">Leave</button>
            @elseif ($others->first())
                <button type="button" wire:click="block" wire:confirm="Block {{ '@'.$others->first()->username }}? Neither of you will see the other’s posts or messages." class="rounded-lg border border-rule px-2.5 py-1">Block</button>
            @endif
        </div>
    </div>

    @if ($conversation->is_group)
        <details class="mt-2 rounded-xl border border-rule bg-card p-3 text-sm">
            <summary class="cursor-pointer text-mute">{{ $conversation->participants->count() }} people</summary>
            <ul class="mt-2 space-y-1">
                @foreach ($conversation->participants as $p)
                    <li class="flex items-center gap-2">
                        <a href="{{ route('users.show', $p) }}" wire:navigate>{{ $p->name }}</a> <span class="text-mute">{{ '@'.$p->username }}{{ $p->pivot->role === 'admin' ? ' · admin' : '' }}</span>
                        @if ($conversation->isAdmin(auth()->user()) && $p->id !== auth()->id())
                            <button type="button" wire:click="removeMember({{ $p->id }})" class="ml-auto text-xs underline">Remove</button>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if ($conversation->isAdmin(auth()->user()))
                <form wire:submit="rename" class="mt-3 flex gap-2">
                    <input wire:model="newName" maxlength="60" placeholder="Group name" aria-label="Group name" class="flex-1 rounded-md border border-rule bg-paper px-2 py-1">
                    <button class="rounded-md border border-rule px-2.5">Rename</button>
                </form>
                <form wire:submit="addMember" class="mt-2 flex gap-2">
                    <input wire:model="addUsername" placeholder="Add someone by username" aria-label="Add someone" class="flex-1 rounded-md border border-rule bg-paper px-2 py-1">
                    <button class="rounded-md border border-rule px-2.5">Add</button>
                </form>
                @error('addUsername') <p class="mt-1 text-flag">{{ $message }}</p> @enderror
                @error('to') <p class="mt-1 text-flag">{{ $message }}</p> @enderror
            @endif
        </details>
    @endif

    @unless ($pivot->accepted_at)
        <div class="mt-3 rounded-xl border border-rule bg-card p-4 text-sm">
            <p><b>{{ $others->first()?->name }}</b> wants to send you messages. They won’t know you’ve seen this unless you accept.</p>
            <div class="mt-2 flex gap-2">
                <button type="button" wire:click="accept" class="rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Accept</button>
                <button type="button" wire:click="decline" class="rounded-lg border border-rule px-3 py-1.5">Decline</button>
            </div>
        </div>
    @endunless

    <div class="mt-3 space-y-2" wire:poll.5s.visible>
        @foreach ($messages as $m)
            @php($mine = $m->user_id === auth()->id())
            <div wire:key="m-{{ $m->id }}" @class(['flex', 'justify-end' => $mine])>
                <div @class(['group max-w-[80%] rounded-xl px-3 py-2 text-[14.5px]', 'bg-ink text-paper' => $mine, 'border border-rule bg-card' => ! $mine])>
                    @if ($conversation->is_group && ! $mine)<div class="text-[12px] font-semibold">{{ $m->user?->name ?? 'Deleted account' }}</div>@endif
                    @if ($m->replyTo)
                        <div class="mb-1 border-l-2 border-current/30 pl-2 text-[12.5px] opacity-75">{{ $m->replyTo->user?->name }}: {{ str($m->replyTo->body)->limit(60) }}</div>
                    @endif
                    @if ($m->body !== '')<div class="whitespace-pre-line break-words">{{ $m->body }}</div>@endif
                    @foreach ($m->images as $img)
                        <a href="{{ $img->url() }}" target="_blank"><img src="{{ $img->url() }}" alt="" class="mt-1.5 max-h-64 rounded-lg"></a>
                    @endforeach
                    <div class="mt-1 flex flex-wrap items-center gap-1.5 text-[11.5px] opacity-70">
                        <span title="{{ $m->created_at->toDayDateTimeString() }}">{{ $m->created_at->format('g:ia') }}</span>
                        @foreach ($m->reactions->groupBy('emoji') as $emoji => $rs)
                            <button type="button" wire:click="react({{ $m->id }}, @js($emoji))" class="rounded-full bg-chip px-1.5 text-ink">{{ $emoji }} {{ $rs->count() }}</button>
                        @endforeach
                        <span class="hidden gap-1 group-hover:inline-flex group-focus-within:inline-flex">
                            @foreach (['❤️', '😂', '👍'] as $quick)
                                <button type="button" wire:click="react({{ $m->id }}, '{{ $quick }}')" aria-label="React {{ $quick }}">{{ $quick }}</button>
                            @endforeach
                            <button type="button" wire:click="$set('replyTo', {{ $m->id }})" class="underline">Reply</button>
                            @unless ($mine)<button type="button" wire:click="$set('reportingMessage', {{ $m->id }})" class="underline">Report</button>@endunless
                        </span>
                    </div>
                </div>
            </div>
        @endforeach

        @if ($seenAt && $messages->last()?->user_id === auth()->id() && $seenAt->gte($messages->last()->created_at))
            <p class="text-right text-[12px] text-mute">Seen</p>
        @endif
        @if ($typing->isNotEmpty())
            <p class="text-[12.5px] text-mute">{{ $typing->join(', ') }} {{ $typing->count() > 1 ? 'are' : 'is' }} typing…</p>
        @endif
    </div>

    @if ($pivot->accepted_at)
        <form wire:submit="send" class="mt-3 rounded-xl border border-rule bg-card p-3">
            @if ($replyTo)
                <div class="mb-2 flex justify-between text-[13px] text-mute">Replying to a message <button type="button" wire:click="$set('replyTo', null)" aria-label="Cancel reply">×</button></div>
            @endif
            <label for="msg-body" class="sr-only">Message</label>
            <textarea id="msg-body" wire:model.live.debounce.1500ms="body" rows="2" maxlength="{{ \App\Models\Message::MAX_LENGTH }}" placeholder="Write a message"
                x-on:keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); $wire.send() }" class="w-full resize-none bg-transparent outline-none"></textarea>
            @error('body') <p class="text-sm text-flag">{{ $message }}</p> @enderror
            @error('photos.*') <p class="text-sm text-flag">{{ $message }}</p> @enderror
            <div class="mt-1 flex items-center gap-2">
                <label class="cursor-pointer rounded-md border border-rule px-2 py-1 text-[13px] text-mute">Photo<input type="file" wire:model="photos" multiple accept="image/jpeg,image/png,image/webp" class="sr-only"></label>
                @if ($photos)<span class="text-[13px] text-mute">{{ count($photos) }} {{ str('photo')->plural(count($photos)) }} attached</span>@endif
                <span class="ml-auto text-[12px] text-mute">Enter to send · Shift+Enter for a new line</span>
                <button type="submit" class="rounded-lg bg-flag px-3.5 py-1.5 text-[13px] font-semibold text-white">Send</button>
            </div>
        </form>
    @endif

    @if ($reportingMessage)
        <div class="fixed inset-0 z-30 grid place-items-center bg-black/40 p-4" wire:click.self="$set('reportingMessage', null)">
            <form wire:submit="reportMessage" class="w-full max-w-sm rounded-xl border border-rule bg-card p-5" role="dialog" aria-labelledby="rm-title">
                <h2 id="rm-title" class="font-display text-xl font-bold">Report this message</h2>
                <p class="mt-1 text-sm text-mute">A moderator will read it, along with the messages around it.</p>
                <fieldset class="mt-3 space-y-1.5">
                    @foreach (config('stoop.report_reasons') as $key => $label)
                        <label class="flex items-center gap-2 text-sm"><input type="radio" wire:model="reportReason" value="{{ $key }}"> {{ $label }}</label>
                    @endforeach
                </fieldset>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" wire:click="$set('reportingMessage', null)" class="rounded-lg border border-rule px-3 py-1.5 text-sm">Cancel</button>
                    <button type="submit" class="rounded-lg bg-flag px-3 py-1.5 text-sm font-semibold text-white">Report</button>
                </div>
            </form>
        </div>
    @endif
</div>
