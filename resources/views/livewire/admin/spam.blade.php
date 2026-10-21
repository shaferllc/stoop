<div>
    <h1 class="mb-2 font-display text-3xl font-bold">Spam</h1>
    @include('partials.admin-nav')

    <div class="flex flex-wrap items-center gap-2 text-sm">
        <select wire:model.live="status" aria-label="Show" class="rounded-lg border border-rule bg-card px-2 py-1.5">
            <option value="open">Open</option><option value="actioned">Handled</option><option value="dismissed">Dismissed</option><option value="all">All</option>
        </select>
        <label class="flex items-center gap-1.5 text-mute">Timeout
            <select wire:model="hours" class="rounded-lg border border-rule bg-card px-2 py-1.5 text-ink">
                @foreach (\App\Models\Penalty::TIMEOUTS as $h => $label)<option value="{{ $h }}">{{ $label }}</option>@endforeach
            </select>
        </label>
        <button type="button" wire:click="detect" class="ml-auto rounded-lg border border-rule px-3 py-1.5">Run detection now</button>
    </div>
    <p class="mt-2 text-[12px] text-mute">Checked every 10 minutes: near-identical posts from different accounts, bursts of new accounts in one thread, the same link from new accounts, and new accounts messaging many people. Acting on a cluster hides its members’ posts and puts them in a timeout. Bans stay one person at a time.</p>

    <ul class="mt-3 space-y-3">
        @forelse ($clusters as $c)
            <li wire:key="cluster-{{ $c->id }}" class="rounded-xl border border-rule bg-card p-4 text-sm">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded bg-chip px-1.5 text-[11px] font-bold uppercase">{{ \App\Models\SpamCluster::KINDS[$c->kind] ?? $c->kind }}</span>
                    <b>{{ $c->summary }}</b>
                    <span class="text-mute">{{ $c->updated_at->diffForHumans() }}</span>
                    @if ($c->status === 'open')
                        <button type="button" wire:click="act({{ $c->id }})" wire:confirm="Hide every post from these {{ count($c->members) }} accounts and put them in a timeout?" class="ml-auto rounded-lg bg-flag px-3 py-1 font-semibold text-white">Hide posts and time out</button>
                        <button type="button" wire:click="dismiss({{ $c->id }})" class="rounded-lg border border-rule px-3 py-1">Not spam</button>
                    @else
                        <span class="ml-auto text-mute">{{ $c->status === 'actioned' ? 'Handled' : 'Dismissed' }} {{ $c->actioned_at?->diffForHumans() }}</span>
                    @endif
                </div>
                <p class="mt-2 flex flex-wrap gap-1.5">
                    @foreach ($c->members as $id)
                        @if ($p = $people[$id] ?? null)
                            <a href="{{ route('admin.users.show', $p) }}" wire:navigate class="rounded-full border border-rule px-2 py-0.5 hover:underline">{{ '@'.$p->username }} <span class="text-mute">· {{ $p->created_at->diffForHumans(null, true) }} old</span></a>
                        @endif
                    @endforeach
                </p>
                <details class="mt-2">
                    <summary class="cursor-pointer text-mute">Evidence ({{ count($c->evidence) }})</summary>
                    <ul class="mt-1 divide-y divide-rule">
                        @foreach ($c->evidence as $e)
                            <li class="py-1.5"><b>{{ '@'.($e['username'] ?? '?') }}</b> {{ $e['text'] }} @if ($e['dispatch_id'])<a href="{{ route('dispatches.show', $e['dispatch_id']) }}" class="text-mute underline">#{{ $e['dispatch_id'] }}</a>@endif</li>
                        @endforeach
                    </ul>
                </details>
            </li>
        @empty
            <li class="rounded-xl border border-rule bg-card p-6 text-center text-sm text-mute">No clusters here.</li>
        @endforelse
    </ul>
</div>
