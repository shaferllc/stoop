@php
    use App\Models\Dispatch;

    $viewer = auth()->user();
    $argued = Dispatch::topLevel()->visibleTo($viewer)
        ->where('created_at', '>=', now()->subDays(3))
        ->withCount(['replies' => fn ($q) => $q->where('status', 'approved')])
        ->orderByDesc('replies_count')->take(3)->get();
    $happening = Dispatch::topLevel()->visibleTo($viewer)
        ->where('type', 'happening')->where('created_at', '>=', now()->subDay())
        ->latest()->take(3)->get();
@endphp

<div class="rounded-xl border border-rule bg-card p-3.5">
    <div class="mb-2 text-[10.5px] font-semibold uppercase tracking-[0.13em] text-mute">Being argued about</div>
    <ul class="divide-y divide-dashed divide-rule text-[13.5px]">
        @forelse ($argued as $d)
            <li class="py-1.5"><a href="{{ route('dispatches.show', $d) }}" wire:navigate class="hover:underline">{{ str($d->body)->limit(48) }}</a> <span class="text-mute">· {{ $d->replies_count }}</span></li>
        @empty
            <li class="py-1.5 text-mute">Quiet out there. Start something.</li>
        @endforelse
    </ul>
</div>

<div class="rounded-xl border border-rule bg-card p-3.5">
    <div class="mb-2 text-[10.5px] font-semibold uppercase tracking-[0.13em] text-mute">Happening</div>
    <ul class="divide-y divide-dashed divide-rule text-[13.5px]">
        @forelse ($happening as $d)
            <li class="py-1.5"><a href="{{ route('dispatches.show', $d) }}" wire:navigate class="hover:underline">{{ str($d->body)->limit(40) }}</a> <span class="text-mute">· {{ $d->happening_at }}</span></li>
        @empty
            <li class="py-1.5 text-mute">Nothing posted for today yet.</li>
        @endforelse
    </ul>
</div>
