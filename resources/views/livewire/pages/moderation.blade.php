<div>
    <h1 class="font-display text-3xl font-bold">Moderation</h1>
    <p class="mb-4 text-sm text-mute">Held posts, posts hidden after reports, and anything with open reports. Approve to put it back up, remove to take it down for good.</p>

    <div class="space-y-3">
        @forelse ($queue as $d)
            <div wire:key="mod-{{ $d->id }}" class="rounded-xl border border-rule bg-card p-4">
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <a href="{{ route('users.show', $d->user) }}" class="font-semibold">{{ '@'.$d->user->username }}</a>
                    <span class="rounded bg-chip px-1.5 text-[11px] font-bold uppercase">{{ $d->status }}</span>
                    <span class="text-mute">{{ $d->created_at->diffForHumans() }}</span>
                    @if ($d->user->isMuted()) <span class="text-flag">muted until {{ $d->user->muted_until->format('M j, g:ia') }}</span> @endif
                </div>
                <p class="mt-2 whitespace-pre-line">{{ $d->body }}</p>
                @if ($d->reports->isNotEmpty())
                    <ul class="mt-2 space-y-0.5 text-[13px] text-mute">
                        @foreach ($d->reports as $r)
                            <li>{{ '@'.$r->user->username }}: {{ config("stoop.report_reasons.{$r->reason}") }}{{ $r->note ? ' · “'.$r->note.'”' : '' }}{{ $r->resolved_at ? ' (resolved)' : '' }}</li>
                        @endforeach
                    </ul>
                @endif
                <div class="mt-3 flex flex-wrap gap-2 text-sm">
                    <button type="button" wire:click="approve({{ $d->id }})" class="rounded-lg border border-rule px-3 py-1">Approve</button>
                    <button type="button" wire:click="remove({{ $d->id }})" class="rounded-lg bg-flag px-3 py-1 font-semibold text-white">Remove</button>
                    <button type="button" wire:click="muteAuthor({{ $d->id }}, 24)" class="rounded-lg border border-rule px-3 py-1">Mute author 24h</button>
                    <button type="button" wire:click="muteAuthor({{ $d->id }}, 168)" class="rounded-lg border border-rule px-3 py-1">Mute author 7 days</button>
                </div>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-rule p-8 text-center text-mute">The queue is empty.</div>
        @endforelse
    </div>
</div>
