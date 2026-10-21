<div class="mt-2 text-[13px]">
    @if ($kind === 'dispatch')
        @if ($s = $data['suggestion'])
            <div class="flex flex-wrap items-center gap-2 rounded-lg bg-chip px-3 py-2">
                <span class="font-semibold">Claude suggests {{ $s['action'] === 'approve' ? 'approving' : 'removing' }}{{ $s['rule'] ? ': '.$s['rule'] : '' }}{{ $s['action'] === 'remove' && $s['penalty'] !== 'none' ? ' · '.($s['penalty'] === 'warning' ? 'warning' : (\App\Models\Penalty::TIMEOUTS[(int) $s['penalty']] ?? $s['penalty']).' timeout') : '' }}</span>
                <span class="rounded bg-card px-1.5 text-[11px] font-bold uppercase">{{ $s['confidence'] }} confidence</span>
                <span class="basis-full text-mute">{{ $s['explanation'] }}</span>
                @if ($s['action'] === 'approve')
                    <button type="button" data-accept wire:click="$parent.approve({{ $subjectId }})" class="rounded-lg border border-rule bg-card px-2.5 py-0.5">Accept: approve</button>
                @else
                    <button type="button" data-accept wire:click="$parent.startAction({{ $subjectId }}, @js($s['rule']), @js($s['penalty']))" class="rounded-lg border border-rule bg-card px-2.5 py-0.5">Accept: remove…</button>
                @endif
            </div>
        @elseif (! $ai)
            <p class="text-mute">Claude’s suggestion needs ANTHROPIC_API_KEY.</p>
        @endif
        @php $past = $data['past']; @endphp
        @if ($past['approved'] + $past['removed'])
            <details class="mt-1.5">
                <summary class="cursor-pointer text-mute">Similar past posts: {{ $past['approved'] }} approved, {{ $past['removed'] }} removed</summary>
                <ul class="mt-1 space-y-1 pl-3">
                    @foreach ($past['examples'] as $e)
                        <li><span @class(['rounded px-1 text-[11px] font-bold uppercase', 'bg-flag/10 text-flag' => $e['status'] === 'removed', 'bg-chip' => $e['status'] !== 'removed'])>{{ $e['status'] }}</span> <a href="{{ route('dispatches.show', $e['id']) }}" class="hover:underline">{{ $e['body'] }}</a>{{ $e['reason'] ? ' · '.$e['reason'] : '' }}</li>
                    @endforeach
                </ul>
            </details>
        @else
            <p class="mt-1.5 text-mute">No similar posts decided before.</p>
        @endif
    @elseif ($kind === 'appeal')
        @if ($n = $data['notes'])
            <div class="rounded-lg bg-chip px-3 py-2">
                <p><span class="font-semibold">Claude’s read:</span> {{ $n['summary'] }}</p>
                @foreach (['supports' => 'Supports the appeal', 'contradicts' => 'Contradicts the appeal'] as $key => $label)
                    @if ($n[$key])
                        <p class="mt-1 font-medium">{{ $label }}</p>
                        <ul class="list-disc pl-5">@foreach ($n[$key] as $point)<li>{{ $point }}</li>@endforeach</ul>
                    @endif
                @endforeach
            </div>
        @elseif (! $ai)
            <p class="text-mute">The appeal helper needs ANTHROPIC_API_KEY.</p>
        @endif
    @else
        <p class="whitespace-pre-line">{{ $data['text'] }}</p>
        <p class="mt-1 text-[12px] text-mute">{{ $data['ai'] ? 'Written by Claude from their record and posts; refreshed hourly.' : ($ai ? 'Plain facts (Claude couldn’t write a brief just now).' : 'Plain facts. A written brief on tone needs ANTHROPIC_API_KEY.') }}</p>
    @endif
</div>
