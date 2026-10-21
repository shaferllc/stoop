<div>
    <h1 class="mb-2 font-display text-3xl font-bold">Insights</h1>
    @include('partials.admin-nav')

    <h2 class="font-display text-xl font-bold">OpenAI thresholds, last 30 days</h2>
    <p class="mt-1 text-sm text-mute">Held posts moderators approved or removed, by category. A post held for two categories counts in both. Suggestions follow simple rules (5+ decisions; mostly approved → raise by 0.05, mostly removed → lower by 0.05). Read-only: change thresholds in <code class="font-mono">config/stoop.php</code>.</p>
    <div class="mt-2 overflow-x-auto rounded-xl border border-rule bg-card">
        <table class="w-full text-left text-sm">
            <thead class="text-mute"><tr><th class="px-3 py-2">Category</th><th class="px-3 py-2">Threshold</th><th class="px-3 py-2">Held</th><th class="px-3 py-2">Approved</th><th class="px-3 py-2">Removed</th><th class="px-3 py-2">Suggested</th><th class="px-3 py-2">Why</th></tr></thead>
            <tbody class="divide-y divide-rule">
                @foreach ($thresholds as $t)
                    <tr>
                        <td class="px-3 py-1.5 font-mono">{{ $t['category'] }}</td>
                        <td class="px-3 py-1.5">{{ number_format($t['threshold'], 2) }}</td>
                        <td class="px-3 py-1.5">{{ $t['held'] }}</td>
                        <td class="px-3 py-1.5">{{ $t['approved'] }}{{ $t['approval_rate'] !== null ? ' ('.round($t['approval_rate'] * 100).'%)' : '' }}</td>
                        <td class="px-3 py-1.5">{{ $t['removed'] }}</td>
                        <td @class(['px-3 py-1.5', 'font-semibold text-flag' => $t['suggested'] !== null])>{{ $t['suggested'] !== null ? number_format($t['suggested'], 2) : '—' }}</td>
                        <td class="px-3 py-1.5 text-mute">{{ $t['advice'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <h2 class="mt-6 font-display text-xl font-bold">Watch phrases</h2>
    <p class="mt-1 text-sm text-mute">Coded and local phrases (dog whistles, DC stand-ins for race or class). New posts with an active phrase are held for review, and moderators see the meaning. {{ $ai ? 'Claude also uses this list when it takes a second look at borderline posts.' : 'With ANTHROPIC_API_KEY set, Claude also uses this list for a second look at borderline posts.' }}</p>
    <form wire:submit="save" class="mt-2 rounded-xl border border-rule bg-card p-4 text-sm">
        <div class="grid gap-2 sm:grid-cols-[1fr_2fr_auto]">
            <input wire:model="phrase" maxlength="120" placeholder="Phrase" aria-label="Phrase" class="rounded-md border border-rule bg-paper px-2.5 py-1.5">
            <input wire:model="meaning" maxlength="500" placeholder="What it means, for moderators" aria-label="Meaning" class="rounded-md border border-rule bg-paper px-2.5 py-1.5">
            <select wire:model="severity" aria-label="Severity" class="rounded-md border border-rule bg-paper px-2 py-1.5">
                @foreach (\App\Support\WatchPhrases::SEVERITIES as $s)<option value="{{ $s }}">{{ ucfirst($s) }}</option>@endforeach
            </select>
        </div>
        @foreach (['phrase', 'meaning', 'severity'] as $f) @error($f) <p class="text-flag">{{ $message }}</p> @enderror @endforeach
        <div class="mt-2 flex gap-2">
            <button class="rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">{{ $editing ? 'Save changes' : 'Add phrase' }}</button>
            @if ($editing)<button type="button" wire:click="$set('editing', null)" class="rounded-lg border border-rule px-3 py-1.5">Cancel</button>@endif
        </div>
    </form>
    <ul class="mt-3 divide-y divide-rule rounded-xl border border-rule bg-card text-sm">
        @forelse ($phrases as $p)
            <li wire:key="wp-{{ $p->id }}" class="flex items-start gap-3 px-4 py-2.5">
                <div class="min-w-0 flex-1">
                    <p @class(['font-semibold', 'text-mute line-through' => ! $p->active])>“{{ $p->phrase }}” <span class="ml-1 rounded bg-chip px-1.5 text-[11px] font-bold uppercase">{{ $p->severity }}</span></p>
                    <p class="text-mute">{{ $p->meaning }}</p>
                </div>
                <button type="button" wire:click="edit({{ $p->id }})" class="rounded-lg border border-rule px-2.5 py-0.5">Edit</button>
                <button type="button" wire:click="toggle({{ $p->id }})" class="rounded-lg border border-rule px-2.5 py-0.5">{{ $p->active ? 'Turn off' : 'Turn on' }}</button>
                <button type="button" wire:click="delete({{ $p->id }})" wire:confirm="Delete this watch phrase?" class="rounded-lg border border-rule px-2.5 py-0.5 text-flag">Delete</button>
            </li>
        @empty
            <li class="p-6 text-center text-mute">No watch phrases yet.</li>
        @endforelse
    </ul>
</div>
