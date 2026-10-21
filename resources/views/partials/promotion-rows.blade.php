{{-- An owner's promotions with their status and delivery. Needs $promotions. --}}
<table class="w-full text-left text-sm">
    <thead class="text-[11px] uppercase tracking-wide text-mute"><tr><th class="py-1">Promotion</th><th>Dates</th><th>Status</th><th class="text-right">Impressions</th><th class="text-right">Clicks</th></tr></thead>
    <tbody class="divide-y divide-rule">
        @foreach ($promotions as $p)
            @php($d = \App\Support\Promotions::delivery($p))
            <tr>
                <td class="py-1.5">{{ ucfirst($p->kind) }} · {{ $p->name }} @if ($p->free)<span class="text-mute">(free monthly)</span>@endif</td>
                <td class="text-mute">{{ $p->starts_on->format('M j') }}–{{ $p->ends_on->format('M j') }}</td>
                <td>{{ match ($p->status) { 'review' => 'Waiting for review', 'approved' => 'Approved', 'rejected' => 'Not approved'.($p->refunded_at ? ', refunded' : ''), default => ucfirst($p->status) } }}</td>
                <td class="text-right tabular-nums">{{ number_format($d['impressions']) }} / {{ number_format($p->impressions) }}</td>
                <td class="text-right tabular-nums">{{ number_format($d['clicks']) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
