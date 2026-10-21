<div>
    <h1 class="font-display text-3xl font-bold">Moderation</h1>
    @if (auth()->user()->hasRole('moderator') && \App\Models\Promotion::where('status', 'review')->exists())
        <livewire:admin.promotion-queue />
    @endif

    <div class="mt-3 mb-4 flex gap-0.5 border-b border-rule">
        @foreach (['queue' => 'Queue ('.$queue->count().')', 'appeals' => 'Appeals ('.$appeals->count().')', 'messages' => 'Messages ('.$messageReports->count().')', 'penalties' => 'Timeouts & bans ('.$penalties->count().')'] as $key => $label)
            <button type="button" wire:click="$set('view', '{{ $key }}')" @class(['border-b-2 px-3.5 py-2 text-sm font-medium', 'border-flag' => $view === $key, 'border-transparent text-mute' => $view !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($view === 'queue')
        <p class="mb-3 text-sm text-mute">Held posts, posts hidden after reports, flagged pile-ons, and anything with open reports. Keys: <kbd class="font-mono">j</kbd>/<kbd class="font-mono">k</kbd> to move, <kbd class="font-mono">a</kbd> to approve, <kbd class="font-mono">r</kbd> to remove, <kbd class="font-mono">s</kbd> to accept Claude’s suggestion, <kbd class="font-mono">o</kbd> to open the thread. Posts with high hate, harassment, sexual or violence scores, or a watch phrase, stay blurred until you click.</p>
        <div class="space-y-3" x-data x-on:keydown.window="
            if ($event.metaKey || $event.ctrlKey || $event.altKey || $event.target.closest('input, textarea, select') || $wire.acting) return;
            const items = [...$el.querySelectorAll('[data-mod-item]')];
            if (!items.length) return;
            const current = document.activeElement.closest('[data-mod-item]');
            const i = items.indexOf(current);
            const go = (n) => { const next = items[Math.max(0, Math.min(items.length - 1, n))]; next.focus(); next.scrollIntoView({ block: 'center' }); };
            if ($event.key === 'j') { $event.preventDefault(); go(i + 1); }
            if ($event.key === 'k') { $event.preventDefault(); go(i - 1); }
            if (!current) return;
            if ($event.key === 'a') { $event.preventDefault(); $wire.approve(Number(current.dataset.modItem)); }
            if ($event.key === 'r') { $event.preventDefault(); $wire.startAction(Number(current.dataset.modItem)); }
            if ($event.key === 's') { $event.preventDefault(); current.querySelector('[data-accept]')?.click(); }
            if ($event.key === 'o') { $event.preventDefault(); window.open(current.dataset.href, '_blank'); }
        ">
            @forelse ($queue as $d)
                <div wire:key="mod-{{ $d->id }}" data-mod-item="{{ $d->id }}" data-href="{{ route('dispatches.show', $d) }}" tabindex="-1" class="rounded-xl border border-rule bg-card p-4 outline-none focus:border-ink focus:ring-2 focus:ring-ink">
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <a href="{{ route('admin.users.show', $d->user) }}" wire:navigate class="font-semibold" title="Account, notes and history">{{ '@'.$d->user->username }}</a>
                        <span class="rounded bg-chip px-1.5 text-[11px] font-bold uppercase">{{ $d->status }}</span>
                        @if ($d->flagged_at) <span class="rounded bg-flag/10 px-1.5 text-[11px] font-bold uppercase text-flag">pile-on</span> @endif
                        <span class="text-mute">{{ $d->created_at->diffForHumans() }} · {{ $d->strikes }} prior {{ str('penalty')->plural($d->strikes) }}</span>
                        <a href="{{ route('dispatches.show', $d) }}" class="ml-auto text-mute underline">Open thread</a>
                    </div>
                    @php $blur = \App\Support\ModerationInsights::blur($d); @endphp
                    <div x-data="{ shown: @js(! $blur) }" class="relative mt-2">
                        <p @class(['whitespace-pre-line', 'blur-sm select-none' => $blur]) x-bind:class="{ 'blur-sm select-none': ! shown }">{{ $d->body }}</p>
                        @if ($blur)<button type="button" x-show="! shown" x-on:click="shown = true" class="absolute inset-0 grid place-items-center text-sm font-semibold">Possibly hateful or graphic. Click to show.</button>@endif
                    </div>
                    @if ($d->screening['openai'] ?? null)
                        <p class="mt-2 text-[13px] text-mute">Held by the automatic check:
                            @foreach ($d->screening['openai'] as $category => $score)
                                <span class="mr-1 rounded bg-flag/10 px-1.5 font-mono text-[12px] text-flag">{{ $category }} {{ number_format($score, 2) }}</span>
                            @endforeach
                        </p>
                    @elseif (($d->screening['claude'] ?? null) === 'hold')
                        <p class="mt-2 text-[13px] text-mute">Held by the house-rules check (Claude).</p>
                    @endif
                    @foreach (\App\Support\ModerationInsights::heldBecause($d) as $why)
                        <p class="mt-2 text-[13px] text-flag">Held: {{ $why }}</p>
                    @endforeach
                    <livewire:admin.assist kind="dispatch" :subject-id="$d->id" :key="'assist-'.$d->id" />
                    @if ($d->reports->isNotEmpty())
                        <ul class="mt-2 space-y-0.5 text-[13px] text-mute">
                            @foreach ($d->reports as $r)
                                <li>{{ '@'.$r->user->username }}: {{ config("stoop.report_reasons.{$r->reason}") }}{{ $r->note ? ' · “'.$r->note.'”' : '' }}{{ $r->resolved_at ? ' (resolved)' : '' }}</li>
                            @endforeach
                        </ul>
                    @endif
                    <div class="mt-3 flex flex-wrap gap-2 text-sm">
                        <button type="button" wire:click="approve({{ $d->id }})" class="rounded-lg border border-rule px-3 py-1">Approve</button>
                        <button type="button" wire:click="startAction({{ $d->id }})" class="rounded-lg bg-flag px-3 py-1 font-semibold text-white">Remove…</button>
                    </div>
                </div>
            @empty
                <div class="rounded-xl border border-dashed border-rule p-8 text-center text-mute">The queue is empty.</div>
            @endforelse
        </div>
    @elseif ($view === 'appeals')
        <p class="mb-3 text-sm text-mute">Appeals you didn’t make the original call on. Granting reverses the removal or lifts the penalty.</p>
        <div class="space-y-3">
            @forelse ($appeals as $a)
                <div wire:key="appeal-{{ $a->id }}" class="rounded-xl border border-rule bg-card p-4 text-sm">
                    <div><b>{{ '@'.$a->user->username }}</b> appeals {{ $a->penalty ? 'a '.strtolower($a->penalty->label()) : 'a removed dispatch' }} · <span class="text-mute">{{ $a->created_at->diffForHumans() }}</span></div>
                    @if ($a->dispatch)<p class="mt-2 rounded-lg bg-chip px-3 py-2">{{ $a->dispatch->body }}</p>@endif
                    <p class="mt-1 text-mute">Reason given: {{ $a->penalty?->reason ?? $a->dispatch?->moderation_reason }}</p>
                    <p class="mt-2">“{{ $a->body }}”</p>
                    <livewire:admin.assist kind="appeal" :subject-id="$a->id" :key="'appeal-assist-'.$a->id" />
                    <div class="mt-3 flex gap-2">
                        <button type="button" wire:click="decide({{ $a->id }}, true)" class="rounded-lg border border-rule px-3 py-1">Grant</button>
                        <button type="button" wire:click="decide({{ $a->id }}, false)" class="rounded-lg border border-rule px-3 py-1">Deny</button>
                    </div>
                </div>
            @empty
                <div class="rounded-xl border border-dashed border-rule p-8 text-center text-mute">No open appeals.</div>
            @endforelse
        </div>
    @elseif ($view === 'messages')
        <p class="mb-3 text-sm text-mute">Reported private messages, with a few messages either side for context. Read only what you need.</p>
        <div class="space-y-3">
            @forelse ($messageReports as $r)
                <div wire:key="mr-{{ $r->id }}" class="rounded-xl border border-rule bg-card p-4 text-sm">
                    <div><b>{{ '@'.$r->user->username }}</b> reported a message from <b>{{ '@'.($r->message->user?->username ?? 'deleted') }}</b> · {{ config("stoop.report_reasons.{$r->reason}") }}</div>
                    <ol class="mt-2 space-y-1 rounded-lg bg-chip p-2.5">
                        @foreach ($r->context as $m)
                            <li @class(['font-semibold text-flag' => $m->id === $r->message_id])>{{ $m->user?->username ?? 'deleted' }}: {{ $m->body }}</li>
                        @endforeach
                    </ol>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" wire:click="resolveMessageReport({{ $r->id }})" class="rounded-lg border border-rule px-3 py-1">Dismiss</button>
                        <button type="button" wire:click="resolveMessageReport({{ $r->id }}, 24)" class="rounded-lg border border-rule px-3 py-1">Timeout sender 1 day</button>
                        <button type="button" wire:click="resolveMessageReport({{ $r->id }}, 168)" class="rounded-lg border border-rule px-3 py-1">Timeout sender 7 days</button>
                    </div>
                </div>
            @empty
                <div class="rounded-xl border border-dashed border-rule p-8 text-center text-mute">No reported messages.</div>
            @endforelse
        </div>
    @else
        <ul class="divide-y divide-rule rounded-xl border border-rule bg-card text-sm">
            @forelse ($penalties as $p)
                <li wire:key="pen-{{ $p->id }}" class="flex flex-wrap items-center gap-2 px-4 py-3">
                    <b>{{ $p->user ? '@'.$p->user->username : 'Deleted account' }}</b>
                    <span>{{ $p->label() }}</span>
                    <span class="text-mute">· {{ $p->reason }}</span>
                    <button type="button" wire:click="lift({{ $p->id }})" class="ml-auto rounded-lg border border-rule px-3 py-1">Lift</button>
                </li>
            @empty
                <li class="p-8 text-center text-mute">Nobody is in a timeout or banned.</li>
            @endforelse
        </ul>
    @endif

    @if ($actingOn)
        <div class="fixed inset-0 z-30 grid place-items-center bg-black/40 p-4" wire:click.self="$set('acting', null)">
            <form wire:submit="remove" class="w-full max-w-md rounded-xl border border-rule bg-card p-5" role="dialog" aria-labelledby="remove-title">
                <h2 id="remove-title" class="font-display text-xl font-bold">Remove {{ '@'.$actingOn->user->username }}’s dispatch</h2>
                <p class="mt-1 text-sm text-mute">The author is told the reason. Moderators aren’t named.</p>
                <label class="mt-4 block text-sm font-medium" for="mod-reason">Rule broken</label>
                <select id="mod-reason" wire:model="reason" class="mt-1 w-full rounded-lg border border-rule bg-paper p-2 text-sm">
                    @foreach (config('stoop.rule_reasons') as $r)<option>{{ $r }}</option>@endforeach
                </select>
                <label class="mt-3 block text-sm font-medium" for="mod-note">Note to the author (optional)</label>
                <input id="mod-note" wire:model="note" maxlength="200" class="mt-1 w-full rounded-lg border border-rule bg-paper p-2 text-sm">
                <label class="mt-3 block text-sm font-medium" for="mod-penalty">Penalty</label>
                <select id="mod-penalty" wire:model="penalty" class="mt-1 w-full rounded-lg border border-rule bg-paper p-2 text-sm">
                    <option value="none">None, just remove it</option>
                    <option value="warning">Warning</option>
                    @foreach (\App\Models\Penalty::TIMEOUTS as $hours => $label)<option value="{{ $hours }}">Timeout: {{ $label }}</option>@endforeach
                    <option value="ban">Ban</option>
                </select>
                <p class="mt-1 text-[12.5px] text-mute">Ladder: warning, then 1 day, then 7 days, then a ban. Slurs and threats go straight to a ban.</p>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" wire:click="$set('acting', null)" class="rounded-lg border border-rule px-3 py-1.5 text-sm">Cancel</button>
                    <button type="submit" class="rounded-lg bg-flag px-3 py-1.5 text-sm font-semibold text-white">Remove</button>
                </div>
            </form>
        </div>
    @endif
</div>
