<div>
    <h1 class="mb-2 font-display text-3xl font-bold">Business</h1>
    @include('partials.admin-nav')
    @unless ($stripe)
        <p class="mb-3 rounded-lg bg-chip p-2 text-sm">Stripe not configured: Upgrade and Promote are hidden from owners. Set test-mode <code>STRIPE_KEY</code>, <code>STRIPE_SECRET</code>, <code>STRIPE_WEBHOOK_SECRET</code> and <code>STRIPE_PRICE_BUSINESS</code> (see the README). Live keys are refused.</p>
    @endunless

    <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="rounded-lg bg-chip p-3"><dt class="text-[11px] uppercase tracking-wide text-mute">MRR</dt><dd class="font-display text-2xl font-bold tabular-nums">${{ number_format($mrr / 100) }}</dd></div>
        <div class="rounded-lg bg-chip p-3"><dt class="text-[11px] uppercase tracking-wide text-mute">Active subscribers</dt><dd class="font-display text-2xl font-bold tabular-nums">{{ $active }}</dd></div>
        <div class="rounded-lg bg-chip p-3"><dt class="text-[11px] uppercase tracking-wide text-mute">Failed payments (30 days)</dt><dd class="font-display text-2xl font-bold tabular-nums">{{ $failing }}</dd></div>
        <div class="rounded-lg bg-chip p-3"><dt class="text-[11px] uppercase tracking-wide text-mute">Promotions (30 days)</dt><dd class="font-display text-2xl font-bold tabular-nums">${{ number_format($promotionCents / 100) }}</dd></div>
    </dl>

    <div class="mt-4 overflow-x-auto rounded-xl border border-rule bg-card">
        <table class="w-full text-left text-sm">
            <thead class="text-[11px] uppercase tracking-wide text-mute"><tr><th class="px-3 py-2">Owner</th><th>Places</th><th>Status</th><th>Renews / ends</th><th>Failed payments</th><th>Since</th></tr></thead>
            <tbody class="divide-y divide-rule">
                @forelse ($accounts as $a)
                    <tr>
                        <td class="px-3 py-2">{{ $a->user->name }} <span class="text-mute">{{ '@'.$a->user->username }}</span></td>
                        <td class="tabular-nums">{{ $places[$a->user_id] ?? 0 }}</td>
                        <td @class(['text-flag' => ! $a->isActive() || $a->status === 'past_due'])>{{ $a->status }}{{ $a->cancel_at_period_end ? ' (canceling)' : '' }}</td>
                        <td>{{ $a->current_period_end?->format('M j, Y') }}</td>
                        <td class="tabular-nums">{{ $a->failed_payments }}@if ($a->payment_failed_at) <span class="text-mute">· last {{ $a->payment_failed_at->format('M j') }}</span>@endif</td>
                        <td>{{ $a->created_at->format('M j, Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-3 py-3 text-mute">No Business subscribers yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
