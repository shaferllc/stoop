<section class="mt-4 rounded-xl border border-rule bg-card p-4">
    <h2 class="font-display text-xl font-bold">Promotions to review ({{ $queue->count() }})</h2>
    <p class="text-sm text-mute">Self-serve boosts from owners and Happening hosts. Approving runs it as “Promoted · name” through the ad rules; rejecting refunds the payment through Stripe.</p>
    @forelse ($queue as $p)
        @php($target = $p->target())
        @php($blocker = \App\Support\Promotions::blocker($p))
        <div wire:key="promo-{{ $p->id }}" class="mt-3 rounded-lg border border-rule p-3 text-sm">
            <div class="font-semibold">{{ ucfirst($p->kind) }} · {{ $p->name }} <span class="font-normal text-mute">by {{ '@'.$p->user->username }}</span></div>
            @if ($target instanceof \App\Models\PlaceDeal)
                <p><a href="{{ route('places.show', $target->place) }}" class="underline" target="_blank">{{ $target->title }}</a> {{ $target->details }}</p>
            @elseif ($target)
                <p><a href="{{ route('dispatches.show', $target) }}" class="underline" target="_blank">{{ str($target->body)->limit(200) }}</a></p>
            @endif
            <p class="text-mute">
                {{ $p->stops || $p->lines ? implode(', ', [...$p->stops ?? [], ...array_map(fn ($l) => config("stoop.lines.$l.name").' topic', $p->lines ?? [])]) : 'Everywhere' }}
                · {{ $p->starts_on->format('M j') }}–{{ $p->ends_on->format('M j') }} · {{ number_format($p->impressions) }} impressions
                · {{ $p->free ? 'Free monthly' : '$'.number_format($p->amount_cents / 100, 2).' paid' }}
            </p>
            @if ($blocker)<p class="text-flag">{{ $blocker }}</p>@endif
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <button type="button" wire:click="approve({{ $p->id }})" @disabled($blocker) class="rounded-lg bg-flag px-3 py-1 font-semibold text-white disabled:opacity-40">Approve</button>
                <input wire:model="reasons.{{ $p->id }}" maxlength="200" placeholder="Reason (sent to them)" class="rounded-md border border-rule bg-paper px-2 py-1">
                <button type="button" wire:click="reject({{ $p->id }})" wire:confirm="Reject{{ $p->free ? '' : ' and refund' }} this promotion?" class="rounded-lg border border-rule px-3 py-1">Reject{{ $p->free ? '' : ' and refund' }}</button>
            </div>
        </div>
    @empty
        <p class="mt-2 text-sm text-mute">Nothing waiting.</p>
    @endforelse
</section>
