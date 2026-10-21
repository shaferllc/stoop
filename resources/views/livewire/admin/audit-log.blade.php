<div>
    <h1 class="mb-2 font-display text-3xl font-bold">Audit log</h1>
    @include('partials.admin-nav')

    <select wire:model.live="action" aria-label="Action" class="rounded-lg border border-rule bg-card px-2 py-1.5 text-sm">
        <option value="">Every action</option>
        @foreach ($actions as $a)<option>{{ $a }}</option>@endforeach
    </select>

    <div class="mt-3 overflow-x-auto rounded-xl border border-rule bg-card">
        <table class="w-full text-sm">
            <thead class="text-left text-[11px] uppercase tracking-wide text-mute"><tr><th class="px-4 py-2">When</th><th class="px-4 py-2">Who</th><th class="px-4 py-2">Action</th><th class="px-4 py-2">On</th><th class="px-4 py-2">Details</th></tr></thead>
            <tbody class="divide-y divide-rule">
                @forelse ($entries as $e)
                    <tr>
                        <td class="whitespace-nowrap px-4 py-2 text-mute">{{ \Illuminate\Support\Carbon::parse($e->created_at)->format('M j, g:ia') }}</td>
                        <td class="px-4 py-2">{{ $e->actor ? '@'.$e->actor : 'system' }}</td>
                        <td class="px-4 py-2 font-mono text-[12px]">{{ $e->action }}</td>
                        <td class="px-4 py-2">
                            @if ($e->subject_type === 'User')<a href="{{ route('admin.users.show', $e->subject_id) }}" class="underline">Person #{{ $e->subject_id }}</a>
                            @elseif ($e->subject_type === 'Dispatch')<a href="{{ route('dispatches.show', $e->subject_id) }}" class="underline">Dispatch #{{ $e->subject_id }}</a>
                            @elseif ($e->subject_type){{ $e->subject_type }} #{{ $e->subject_id }}@endif
                        </td>
                        <td class="px-4 py-2 font-mono text-[12px] text-mute">{{ $e->details }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-6 text-center text-mute">Nothing logged yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $entries->links() }}</div>
</div>
