@props(['dispatch', 'state' => ['marks' => [], 'votes' => []], 'focus' => false])

@php
    $d = $dispatch;
    $me = auth()->user();
    $marks = $state['marks'][$d->id] ?? [];
    $myVote = $state['votes'][$d->id] ?? null;
    $totalVotes = $d->options->sum('voters_count');
    $btn = 'cursor-pointer hover:text-ink';
@endphp

<article wire:key="dispatch-{{ $d->id }}" id="d{{ $d->id }}" @class(['rounded-xl border bg-card px-4 py-3.5', 'border-ink' => $focus, 'border-rule' => ! $focus])>
    @if ($d->status !== 'approved')
        <div class="mb-2 rounded-md bg-chip px-2.5 py-1 text-xs text-mute">
            {{ match ($d->status) { 'held' => 'Held for review. Only you and moderators can see this.', 'hidden' => 'Hidden after reports. A moderator is reviewing it.', default => 'Removed by a moderator.' } }}
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-2.5">
        <a href="{{ route('users.show', $d->user) }}" wire:navigate class="grid size-9 shrink-0 place-items-center rounded-full text-[13px] font-semibold text-white" style="background: {{ $d->user->color() }}">{{ $d->user->initials() }}</a>
        <div class="min-w-0">
            <a href="{{ route('users.show', $d->user) }}" wire:navigate class="font-semibold hover:underline">{{ $d->user->name }}</a>
            <span class="ml-1.5 rounded border border-rule px-1 text-[10px] font-bold tracking-wide text-mute">{{ $d->user->badge() }}</span>
            <div class="text-[12.5px] text-mute">
                <a href="{{ route('users.show', $d->user) }}" wire:navigate>{{ '@'.$d->user->username }}</a>
                · <a href="{{ route('dispatches.show', $d) }}" wire:navigate class="hover:underline" title="{{ $d->created_at->toDayDateTimeString() }}">{{ $d->created_at->shortAbsoluteDiffForHumans() }}</a>
                @if ($d->type !== 'dispatch' && ! $d->parent_id) · {{ config("stoop.types.{$d->type}") }} @endif
            </div>
        </div>
        @unless ($d->parent_id)
            <a href="{{ route('home', $d->stop ? ['stop' => $d->stop] : ['line' => $d->line]) }}" wire:navigate class="ml-auto flex items-center font-mono text-[11.5px] text-mute hover:text-ink">
                <span class="mr-2 size-2.5 rounded-full" style="background: {{ $d->lineColor() }}"></span>{{ $d->stop ? $d->stop.' · '.$d->stopName() : $d->lineName() }}
            </a>
        @endunless
    </div>

    @if ($d->parent_id && $d->relationLoaded('parent') && $d->parent)
        <div class="mt-2 text-[12.5px] text-mute sm:ml-[46px]">Replying to <a href="{{ route('dispatches.show', $d->parent) }}" wire:navigate class="underline">{{ '@'.$d->parent->user->username }}</a></div>
    @endif

    <div class="mt-2 whitespace-pre-line break-words sm:ml-[46px]">{{ $d->body }}</div>

    @if ($d->happening_at)
        <div class="mt-2 rounded-lg border border-rule px-3 py-2 text-sm text-mute sm:ml-[46px]"><b class="text-ink">{{ $d->happening_at }}</b> · {{ $d->going_count }} going</div>
    @endif

    @if ($d->quoted)
        <a href="{{ route('dispatches.show', $d->quoted) }}" wire:navigate class="mt-2 block rounded-lg border border-rule px-3 py-2 text-sm text-mute hover:border-ink sm:ml-[46px]">
            <b class="text-ink">{{ '@'.$d->quoted->user->username }}</b>: {{ str($d->quoted->body)->limit(160) }}
        </a>
    @endif

    @if ($d->options->isNotEmpty())
        <div class="mt-2 space-y-1.5 sm:ml-[46px]">
            @foreach ($d->options as $option)
                @if ($myVote === null && $d->user_id !== $me?->id)
                    <button type="button" wire:click="vote({{ $d->id }}, {{ $option->id }})" class="flex w-full justify-between rounded-lg border border-rule px-3 py-1.5 text-left hover:border-ink">
                        <span>{{ $option->label }}</span><span class="text-mute">Vote</span>
                    </button>
                @else
                    @php($pct = $totalVotes ? round($option->voters_count / $totalVotes * 100) : 0)
                    <div @class(['relative flex justify-between overflow-hidden rounded-lg border px-3 py-1.5', 'border-ink font-semibold' => $myVote === $option->id, 'border-rule' => $myVote !== $option->id])>
                        <i class="absolute inset-y-0 left-0 bg-chip" style="width: {{ $pct }}%"></i>
                        <span class="relative">{{ $option->label }}{{ $myVote === $option->id ? ' ✓' : '' }}</span>
                        <span class="relative tabular-nums text-mute">{{ $pct }}%</span>
                    </div>
                @endif
            @endforeach
            <div class="text-[12.5px] text-mute">{{ number_format($totalVotes) }} {{ str('vote')->plural($totalVotes) }}{{ $myVote === null && $d->user_id !== $me?->id ? ' · vote to see results' : '' }}</div>
        </div>
    @endif

    <div class="mt-2.5 flex flex-wrap gap-x-4 gap-y-1 text-[13px] text-mute sm:ml-[46px]">
        <a href="{{ route('dispatches.show', $d) }}#reply" wire:navigate class="{{ $btn }}">Reply {{ $d->replies_count }}</a>
        @unless ($d->parent_id)
            <button type="button" wire:click="quote({{ $d->id }})" class="{{ $btn }}">Quote {{ $d->quotes_count }}</button>
        @endunless
        <button type="button" wire:click="toggleMark({{ $d->id }}, 'save')" @class([$btn, 'font-semibold text-flag' => in_array('save', $marks)])>{{ in_array('save', $marks) ? 'Saved' : 'Save' }}</button>
        @if ($d->type === 'been')
            <button type="button" wire:click="toggleMark({{ $d->id }}, 'been')" @class([$btn, 'font-semibold text-flag' => in_array('been', $marks)])>Been here {{ $d->been_count }}</button>
        @endif
        @if ($d->type === 'happening')
            <button type="button" wire:click="toggleMark({{ $d->id }}, 'going')" @class([$btn, 'font-semibold text-flag' => in_array('going', $marks)])>Going {{ $d->going_count }}</button>
        @endif

        @auth
            <flux:dropdown position="bottom" align="start">
                <button type="button" class="{{ $btn }}" aria-label="More actions">···</button>
                <flux:menu>
                    @if ($d->user_id === $me->id || $me->is_staff)
                        <flux:menu.item wire:click="deleteDispatch({{ $d->id }})" wire:confirm="Delete this dispatch? This can’t be undone.">Delete</flux:menu.item>
                    @endif
                    @if ($d->user_id !== $me->id)
                        <flux:menu.item wire:click="startReport({{ $d->id }})">Report</flux:menu.item>
                        <flux:menu.item wire:click="silence({{ $d->user_id }}, 'mute')">Mute {{ '@'.$d->user->username }}</flux:menu.item>
                        <flux:menu.item wire:click="silence({{ $d->user_id }}, 'block')" wire:confirm="Block {{ '@'.$d->user->username }}? You won’t see each other’s posts.">Block {{ '@'.$d->user->username }}</flux:menu.item>
                    @endif
                </flux:menu>
            </flux:dropdown>
        @endauth
    </div>
</article>
