<div>
    <h1 class="mb-2 font-display text-3xl font-bold">People</h1>
    @include('partials.admin-nav')

    <div class="flex flex-wrap gap-2">
        <input type="search" wire:model.live.debounce.300ms="q" placeholder="Name, username or email" aria-label="Search people" class="min-w-0 flex-1 rounded-lg border border-rule bg-card px-3 py-1.5">
        <select wire:model.live="filter" aria-label="Filter" class="rounded-lg border border-rule bg-card px-2 py-1.5 text-sm">
            <option value="all">Everyone</option><option value="staff">Staff</option><option value="penalized">In a timeout or banned</option><option value="unverified">Unverified</option><option value="bots">House accounts</option><option value="ban_review">4+ strikes, review for a ban</option>
        </select>
    </div>

    <div class="mt-3 overflow-x-auto rounded-xl border border-rule bg-card">
        <table class="w-full text-sm">
            <thead class="text-left text-[11px] uppercase tracking-wide text-mute"><tr><th class="px-4 py-2">Person</th><th class="px-4 py-2">Email</th><th class="px-4 py-2">Posts</th><th class="px-4 py-2">Joined</th><th class="px-4 py-2"></th></tr></thead>
            <tbody class="divide-y divide-rule">
                @foreach ($users as $u)
                    <tr wire:key="u-{{ $u->id }}">
                        <td class="px-4 py-2"><a href="{{ route('admin.users.show', $u) }}" wire:navigate class="font-semibold hover:underline">{{ $u->name }}</a> <span class="text-mute">{{ '@'.$u->username }}</span></td>
                        <td class="px-4 py-2 text-mute">{{ $u->email }}</td>
                        <td class="px-4 py-2 tabular-nums">{{ $u->dispatches_count }}</td>
                        <td class="px-4 py-2 text-mute">{{ $u->created_at->toFormattedDateString() }}</td>
                        <td class="px-4 py-2 text-right">
                            @if ($u->is_staff)<span class="rounded bg-chip px-1.5 text-[11px] font-bold">STAFF</span>@endif
                            @if (in_array($u->id, $penalized))<span class="rounded bg-flag px-1.5 text-[11px] font-bold text-white">PENALIZED</span>@endif
                            @unless ($u->email_verified_at)<span class="rounded border border-rule px-1.5 text-[11px]">unverified</span>@endunless
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $users->links() }}</div>
</div>
