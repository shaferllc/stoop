<div>
    <h1 class="mb-2 font-display text-3xl font-bold">Tips</h1>
    @include('partials.admin-nav')

    <p class="mb-3 text-sm text-mute">Readers’ tips to the newsroom. Private to staff: never posted, never shown to anyone else. Reply only goes out if the tipster ticked “OK to contact me”.</p>

    <div class="mb-3 flex flex-wrap gap-1.5 text-sm">
        @foreach (\App\Models\Tip::STATUSES + ['mine' => 'Assigned to me', 'all' => 'All'] as $key => $label)
            <button type="button" wire:click="$set('status', '{{ $key }}')" @class(['rounded-full border px-3 py-0.5', 'border-ink' => $status === $key, 'border-transparent bg-chip text-mute' => $status !== $key])>{{ $label }}@isset($counts[$key]) ({{ $counts[$key] }})@endisset</button>
        @endforeach
    </div>

    <div class="space-y-3">
        @forelse ($tips as $tip)
            <div wire:key="tip-{{ $tip->id }}" class="rounded-xl border border-rule bg-card p-4 text-sm">
                <div class="flex flex-wrap items-center gap-x-2 text-mute">
                    <a href="{{ route('admin.users.show', $tip->user) }}" wire:navigate class="font-semibold text-ink">{{ '@'.$tip->user->username }}</a>
                    <span>· {{ $tip->created_at->diffForHumans() }}</span>
                    <span>· {{ $tip->contact_ok ? 'OK to contact' : 'Don’t contact' }}</span>
                    @foreach ($tip->screening['flagged'] ?? [] as $category => $score)
                        <span class="rounded-full bg-flag/10 px-2 text-[12px] text-flag">{{ $category }} {{ $score }}</span>
                    @endforeach
                </div>
                <p class="mt-1.5 whitespace-pre-line">{{ $tip->body }}</p>
                @if ($tip->link)
                    <p class="mt-1"><a href="{{ $tip->link }}" target="_blank" rel="noopener noreferrer nofollow" class="break-all underline">{{ $tip->link }}</a></p>
                @endif
                @if ($tip->dispatch)
                    <p class="mt-1 text-mute">About <a href="{{ route('dispatches.show', $tip->dispatch_id) }}" class="underline">this dispatch</a>: “{{ str($tip->dispatch->body)->limit(100) }}”</p>
                @endif
                @if ($tip->photos)
                    <div class="mt-2 flex gap-2">
                        @foreach ($tip->photos as $n => $path)
                            <a href="{{ route('admin.tips.photo', [$tip, $n]) }}" target="_blank"><img src="{{ route('admin.tips.photo', [$tip, $n]) }}" alt="Photo {{ $n + 1 }} from the tip" class="size-24 rounded object-cover"></a>
                        @endforeach
                    </div>
                @endif

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <select wire:change="setStatus({{ $tip->id }}, $event.target.value)" aria-label="Status" class="rounded-md border border-rule bg-paper px-1.5 py-1">
                        @foreach (\App\Models\Tip::STATUSES as $key => $label)
                            <option value="{{ $key }}" @selected($tip->status === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <select wire:change="assign({{ $tip->id }}, $event.target.value || null)" aria-label="Assigned to" class="rounded-md border border-rule bg-paper px-1.5 py-1">
                        <option value="">Unassigned</option>
                        @foreach ($editors as $editor)
                            <option value="{{ $editor->id }}" @selected($tip->assigned_to === $editor->id)>{{ '@'.$editor->username }}</option>
                        @endforeach
                    </select>
                </div>

                @if ($tip->contact_ok)
                    <form wire:submit="reply({{ $tip->id }})" class="mt-2 flex gap-2">
                        <input wire:model="replies.{{ $tip->id }}" maxlength="1500" placeholder="Reply to {{ '@'.$tip->user->username }} in Stoop messages" aria-label="Reply to the tipster" class="min-w-0 flex-1 rounded-md border border-rule bg-paper px-2.5 py-1.5">
                        <button class="rounded-lg border border-rule px-3 py-1.5">Send</button>
                    </form>
                @endif
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-rule p-8 text-center text-mute">No tips here.</div>
        @endforelse
    </div>

    <div class="mt-3">{{ $tips->links() }}</div>
</div>
