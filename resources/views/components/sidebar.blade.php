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
    $people = $viewer ? App\Support\Suggestions::for($viewer, 3) : collect();
@endphp

@php($incidents = App\Support\Metro::incidents())
@if ($incidents !== null)
    <div class="rounded-xl border border-rule bg-card p-3.5">
        <div class="mb-2 text-[10.5px] font-semibold uppercase tracking-[0.13em] text-mute">Metro delays</div>
        <ul class="divide-y divide-dashed divide-rule text-[13px]">
            @forelse (collect(config('stoop.lines'))->keys() as $line)
                @php($hits = collect($incidents)->where('line', $line))
                <li class="flex items-start gap-2 py-1.5">
                    <span class="mt-1 size-2.5 shrink-0 rounded-full" style="background: {{ config("stoop.lines.$line.color") }}"></span>
                    <span>{{ ucfirst($line) }} · @if ($hits->isEmpty())<span class="text-mute">No reported delays</span>@else{{ str($hits->first()['message'])->limit(90) }}@endif</span>
                </li>
            @empty
            @endforelse
        </ul>
    </div>
@endif

@if ($people->isNotEmpty())
    <div class="rounded-xl border border-rule bg-card p-3.5">
        <div class="mb-2 text-[10.5px] font-semibold uppercase tracking-[0.13em] text-mute">Who to follow</div>
        <ul class="space-y-2 text-[13.5px]">
            @foreach ($people as $person)
                <li class="flex items-center gap-2">
                    <span class="grid size-7 shrink-0 place-items-center rounded-full text-[10px] font-bold text-white" style="background: {{ $person->color() }}">{{ $person->initials() }}</span>
                    <span class="min-w-0">
                        <a href="{{ route('users.show', $person) }}" wire:navigate class="block truncate font-semibold hover:underline">{{ $person->name }}</a>
                        <span class="block truncate text-[12px] text-mute">{{ $person->reason }}</span>
                    </span>
                </li>
            @endforeach
        </ul>
    </div>
@endif

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
