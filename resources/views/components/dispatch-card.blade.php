@props(['dispatch', 'state' => ['marks' => [], 'votes' => []], 'focus' => false, 'pinned' => false, 'translated' => null])

@php
    $d = $dispatch;
    $me = auth()->user();
    $marks = $state['marks'][$d->id] ?? [];
    $myVote = $state['votes'][$d->id] ?? null;
    $totalVotes = $d->options->sum('voters_count');
    $showResults = $myVote !== null || $d->user_id === $me?->id || $d->isClosed() || ! $me;
    $btn = 'cursor-pointer hover:text-ink';
@endphp

<article wire:key="dispatch-{{ $d->id }}" id="d{{ $d->id }}" tabindex="-1" data-dispatch data-href="{{ route('dispatches.show', $d) }}" @class(['rounded-xl border bg-card px-4 py-3.5', 'border-ink' => $focus, 'border-rule' => ! $focus])>
    @if ($d->status !== 'approved')
        <div class="mb-2 rounded-md bg-chip px-2.5 py-1 text-xs text-mute">
            {{ match ($d->status) {
                'held' => 'Held for review. Only you and moderators can see this.',
                'hidden' => 'Hidden after reports. A moderator is reviewing it.',
                'scheduled' => 'Scheduled for '.$d->publish_at?->format('D M j, g:ia').'. Only you can see this.',
                default => 'Removed by a moderator'.($d->moderation_reason ? ': '.$d->moderation_reason : '').'. You can appeal from the ··· menu.',
            } }}
        </div>
    @endif

    @if ($pinned)
        <div class="mb-1.5 text-[11.5px] font-semibold uppercase tracking-wide text-mute">Pinned</div>
    @endif

    <div class="flex flex-wrap items-center gap-2.5">
        <a href="{{ $d->user->isLive() ? $d->user->live_url : route('users.show', $d->user) }}" @if ($d->user->isLive()) target="_blank" rel="noopener" title="On the scene: {{ $d->user->live_label }}" @else wire:navigate @endif
            @class(['grid size-9 shrink-0 place-items-center rounded-full text-[13px] font-semibold text-white', 'ring-2 ring-flag ring-offset-2 ring-offset-card' => $d->user->isLive()])
            style="background: {{ $d->user->color() }}">{{ $d->user->initials() }}</a>
        <div class="min-w-0">
            <span class="relative" x-data="hoverCard(@js(route('users.card', $d->user)))" x-on:mouseenter="show()" x-on:mouseleave="hide()">
                <a href="{{ route('users.show', $d->user) }}" wire:navigate class="font-semibold hover:underline">{{ $d->user->name }}</a>
                <div x-show="open" x-cloak x-html="html" class="absolute left-0 top-full z-20 mt-1 w-72"></div>
            </span>
            <span class="ml-1.5 rounded border border-rule px-1 text-[10px] font-bold tracking-wide text-mute">{{ $d->user->badge() }}</span>
            @if ($d->user->isLive())<span class="ml-1 rounded bg-flag px-1 text-[10px] font-bold text-white">ON THE SCENE</span>@endif
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
        <div class="mt-2 text-[12.5px] text-mute sm:ml-[46px]">Replying to <a href="{{ route('dispatches.show', $d->parent) }}" wire:navigate class="underline">{{ $d->parent->user ? '@'.$d->parent->user->username : 'a deleted dispatch' }}</a></div>
    @endif

    @if ($d->body !== '')
        <div class="mt-2 sm:ml-[46px]" x-data="{ more: false }">
            <div class="whitespace-pre-line break-words" @if ($d->isLong() && ! $focus) :class="more ? '' : 'line-clamp-6'" @endif>{{ $translated ?? $d->bodyHtml() }}</div>
            @if ($translated)
                <div class="mt-1 text-[12.5px] text-mute">Translated · <button type="button" wire:click="showOriginal({{ $d->id }})" class="underline">Show original</button></div>
            @elseif ($me && \App\Support\Translator::offer($d->body, $me->translation_language ?? 'en'))
                <button type="button" wire:click="translate({{ $d->id }})" class="mt-1 text-[12.5px] text-mute underline">Translate</button>
            @endif
            @if ($d->isLong() && ! $focus)
                <button type="button" x-on:click="more = !more" x-text="more ? 'Show less' : 'Show more'" class="mt-1 text-[13px] font-medium text-flag"></button>
            @endif
        </div>
    @endif

    @if ($d->safety)
        <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 rounded-lg border border-rule px-3 py-2 text-[13px] sm:ml-[46px]">
            @foreach (['when' => 'When', 'block' => 'Where', 'person' => 'Description'] as $field => $label)
                @isset($d->safety[$field])<dt class="text-mute">{{ $label }}</dt><dd>{{ $d->safety[$field] }}</dd>@endisset
            @endforeach
        </dl>
    @endif

    @if ($d->images->isNotEmpty())
        <div @class(['mt-2 grid gap-1.5 overflow-hidden rounded-lg sm:ml-[46px]', 'grid-cols-2' => $d->images->count() > 1])>
            @foreach ($d->images as $image)
                <a href="{{ $image->url() }}" target="_blank" @class(['block', 'col-span-2' => $d->images->count() === 3 && $loop->first])>
                    <img src="{{ $image->url() }}" alt="{{ $image->alt }}" loading="lazy" width="{{ $image->width }}" height="{{ $image->height }}" class="max-h-96 w-full bg-chip object-cover">
                </a>
            @endforeach
        </div>
    @endif

    @if ($d->linkPreview)
        <a href="{{ $d->linkPreview->url }}" target="_blank" rel="nofollow ugc noopener" class="mt-2 block rounded-lg border border-rule px-3 py-2 hover:border-ink sm:ml-[46px]">
            <div class="text-[11px] uppercase tracking-wide text-mute">{{ $d->linkPreview->host() }}</div>
            <div class="font-display text-[16px] font-bold leading-snug">{{ $d->linkPreview->title }}</div>
            @if ($d->linkPreview->description)<div class="line-clamp-2 text-[13px] text-mute">{{ $d->linkPreview->description }}</div>@endif
        </a>
    @endif

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
                @if (! $showResults)
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
            <div class="text-[12.5px] text-mute">
                {{ number_format($totalVotes) }} {{ str('vote')->plural($totalVotes) }}
                @if ($d->closes_at) · {{ $d->isClosed() ? 'Final results' : $d->closes_at->diffForHumans(['parts' => 1, 'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]).' left' }} @endif
                @unless ($showResults) · vote to see results @endunless
            </div>
        </div>
    @endif

    <div class="mt-2.5 flex flex-wrap gap-x-4 gap-y-1 text-[13px] text-mute sm:ml-[46px]">
        <a href="{{ route('dispatches.show', $d) }}#reply" wire:navigate class="{{ $btn }}">Reply {{ $d->replies_count }}</a>
        @unless ($d->parent_id)
            <button type="button" wire:click="quote({{ $d->id }})" class="{{ $btn }}">Quote {{ $d->quotes_count }}</button>
        @endunless
        <button type="button" wire:click="toggleMark({{ $d->id }}, 'save')" @class([$btn, 'font-semibold text-flag' => in_array('save', $marks)])>{{ in_array('save', $marks) ? 'Saved' : 'Save' }}</button>
        @if ($me?->half_smokes_on && $d->user_id !== $me->id)
            <button type="button" wire:click="giveHalfSmoke({{ $d->id }})" class="{{ $btn }}" title="Give a half-smoke ({{ $me->halfSmokesLeft() }} left today)">Half-smoke {{ $d->half_smokes_count ?: '' }}</button>
        @elseif ($me?->half_smokes_on && $d->half_smokes_count)
            <span>{{ $d->half_smokes_count }} {{ str('half-smoke')->plural($d->half_smokes_count) }}</span>
        @endif
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
                    @if ($d->user_id === $me->id && ! $d->parent_id && $d->status === 'approved')
                        <flux:menu.item wire:click="togglePin({{ $d->id }})">{{ $me->pinned_dispatch_id === $d->id ? 'Unpin from profile' : 'Pin to profile' }}</flux:menu.item>
                    @endif
                    @if ($d->parent_id && $d->parent?->user_id === $me->id && $d->user_id !== $me->id)
                        <flux:menu.item wire:click="toggleHideReply({{ $d->id }})">{{ $d->hidden_by_author ? 'Unhide reply' : 'Hide reply' }}</flux:menu.item>
                    @endif
                    @if ($d->status === 'removed' && $d->user_id === $me->id)
                        <flux:menu.item :href="route('appeals.create', ['dispatch' => $d->id])" wire:navigate>Appeal this decision</flux:menu.item>
                    @endif
                    @if ($d->user_id === $me->id || $me->is_staff)
                        <flux:menu.item wire:click="deleteDispatch({{ $d->id }})">Delete</flux:menu.item>
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
