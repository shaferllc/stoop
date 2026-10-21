<div>
    <h1 class="mb-2 font-display text-3xl font-bold">{{ $user->name }} <span class="text-lg text-mute">{{ '@'.$user->username }}</span></h1>
    @include('partials.admin-nav')

    <div class="grid gap-3 lg:grid-cols-2">
        <section class="rounded-xl border border-rule bg-card p-4 text-sm">
            <h2 class="font-semibold">Account</h2>
            <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                <dt class="text-mute">Email</dt><dd>{{ $user->email }} {{ $user->email_verified_at ? '(verified)' : '(unverified)' }}</dd>
                <dt class="text-mute">Joined</dt><dd>{{ $user->created_at->toFormattedDateString() }}</dd>
                <dt class="text-mute">Badge</dt><dd>{{ $user->badge() }}</dd>
                <dt class="text-mute">Posts</dt><dd>{{ $user->dispatches_count }} · {{ $user->followers_count }} followers · {{ $user->following_count }} following</dd>
                <dt class="text-mute">Reported</dt><dd>{{ $reportsAgainst }} {{ str('time')->plural($reportsAgainst) }}</dd>
            </dl>
            <div class="mt-3 flex flex-wrap gap-2">
                <a href="{{ route('users.show', $user) }}" wire:navigate class="rounded-lg border border-rule px-3 py-1">Public profile</a>
                @unless ($user->email_verified_at)<button type="button" wire:click="verify" class="rounded-lg border border-rule px-3 py-1">Verify email</button>@endunless
                @if ($user->id !== auth()->id())
                    <button type="button" wire:click="toggleStaff" wire:confirm="{{ $user->is_staff ? 'Remove staff access?' : 'Give this person staff access, including moderation and admin?' }}" class="rounded-lg border border-rule px-3 py-1">{{ $user->is_staff ? 'Remove staff' : 'Make staff' }}</button>
                    @unless ($user->is_bot)<button type="button" wire:click="deleteAccount" wire:confirm="Delete this account and everything in it? Penalties stay. This can’t be undone." class="rounded-lg border border-flag px-3 py-1 text-flag">Delete account</button>@endunless
                @endif
            </div>
            <form wire:submit="saveSubscription" class="mt-3 flex items-end gap-2">
                <label class="flex-1">Subscribed until<input type="date" wire:model="subscribedUntil" class="mt-1 block w-full rounded-md border border-rule bg-paper px-2 py-1"></label>
                <button class="rounded-lg border border-rule px-3 py-1">Save</button>
            </form>
        </section>

        <section class="rounded-xl border border-rule bg-card p-4 text-sm">
            <h2 class="font-semibold">Penalties <span class="font-normal text-mute">· {{ \App\Support\Strikes::recent($user) }} {{ str('strike')->plural(\App\Support\Strikes::recent($user)) }} in the last {{ \App\Support\Strikes::WINDOW_DAYS }} days</span></h2>
            @if (\App\Support\Strikes::recent($user) >= 4 && ! $user->activeBan())
                <p class="mt-1 rounded-lg bg-flag/10 px-2.5 py-1.5 text-flag">Four or more strikes. Review their posts and decide whether to ban.</p>
            @endif
            <form wire:submit="penalize" class="mt-2 flex flex-wrap gap-2">
                <select wire:model="penaltyType" aria-label="Penalty" class="rounded-md border border-rule bg-paper px-2 py-1">
                    <option value="warning">Warning</option>
                    @foreach (\App\Models\Penalty::TIMEOUTS as $h => $label)<option value="{{ $h }}">Timeout: {{ $label }}</option>@endforeach
                    <option value="ban">Ban</option>
                </select>
                <select wire:model="penaltyReason" aria-label="Reason" class="min-w-0 flex-1 rounded-md border border-rule bg-paper px-2 py-1">
                    @foreach (config('stoop.rule_reasons') as $r)<option>{{ $r }}</option>@endforeach
                </select>
                <button class="rounded-lg bg-flag px-3 py-1 font-semibold text-white">Apply</button>
            </form>
            <ul class="mt-3 space-y-1">
                @forelse ($penalties as $p)
                    <li>{{ $p->label() }} · {{ $p->reason }} · <span class="text-mute">{{ $p->created_at->toFormattedDateString() }}</span>
                        @if ($p->lifted_at) <span class="text-mute">(lifted)</span> @elseif ($p->type !== 'warning') <button type="button" wire:click="lift({{ $p->id }})" class="underline">Lift</button> @endif</li>
                @empty
                    <li class="text-mute">None.</li>
                @endforelse
            </ul>
        </section>

        <section class="rounded-xl border border-rule bg-card p-4 text-sm">
            <h2 class="font-semibold">Brief</h2>
            <livewire:admin.assist kind="user" :subject-id="$user->id" />
        </section>

        <section class="rounded-xl border border-rule bg-card p-4 text-sm">
            <h2 class="font-semibold">Moderator notes</h2>
            <form wire:submit="addNote" class="mt-2">
                <textarea wire:model="note" rows="2" maxlength="2000" placeholder="Only staff see these" aria-label="Note" class="w-full rounded-md border border-rule bg-paper p-2"></textarea>
                <button class="mt-1 rounded-lg border border-rule px-3 py-1">Add note</button>
            </form>
            <ul class="mt-2 space-y-2">
                @foreach ($notes as $n)
                    <li><p class="whitespace-pre-line">{{ $n->body }}</p><p class="text-[12px] text-mute">{{ $n->author ? '@'.$n->author : 'Former staff' }} · {{ \Illuminate\Support\Carbon::parse($n->created_at)->diffForHumans() }}</p></li>
                @endforeach
            </ul>
        </section>

        <section class="rounded-xl border border-rule bg-card p-4 text-sm">
            <h2 class="font-semibold">History</h2>
            <ul class="mt-2 space-y-1">
                @forelse ($audit as $a)
                    <li><span class="font-mono text-[12px]">{{ $a->action }}</span> by {{ $a->actor ? '@'.$a->actor : 'system' }} · <span class="text-mute">{{ \Illuminate\Support\Carbon::parse($a->created_at)->diffForHumans() }}</span></li>
                @empty
                    <li class="text-mute">No staff actions yet.</li>
                @endforelse
            </ul>
        </section>
    </div>

    <h2 class="mt-5 font-display text-xl font-bold">Recent dispatches</h2>
    <ul class="mt-2 divide-y divide-rule rounded-xl border border-rule bg-card text-sm">
        @forelse ($recent as $d)
            <li class="px-4 py-2.5">
                <span class="rounded bg-chip px-1.5 text-[11px] font-bold uppercase">{{ $d->trashed() ? 'deleted' : $d->status }}</span>
                <a href="{{ route('dispatches.show', $d->id) }}" wire:navigate class="hover:underline">{{ str($d->body)->limit(120) ?: '(empty)' }}</a>
                <span class="text-mute">· {{ $d->created_at->diffForHumans() }}</span>
            </li>
        @empty
            <li class="p-6 text-center text-mute">Nothing posted.</li>
        @endforelse
    </ul>
</div>
