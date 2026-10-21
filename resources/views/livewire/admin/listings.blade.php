<div>
    <h1 class="mb-2 font-display text-3xl font-bold">Classifieds</h1>
    @include('partials.admin-nav')
    <p class="text-sm text-mute">Listings held by screening (watch phrases, OpenAI) or by {{ config('stoop.report_threshold') }} reports wait here. Paid in the last 30 days: ${{ number_format($revenue / 100, 2) }}{{ \App\Support\Classifieds::stripeReady() ? '' : ' (Stripe isn’t set up: paid categories show “Coming soon”)' }}.</p>

    <div class="my-3 flex gap-2 text-sm">
        @foreach (['held' => "Held ($held)", 'reported' => 'Reported', 'all' => 'All'] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['rounded-full border px-3 py-0.5', 'border-ink' => $tab === $key, 'border-transparent bg-chip text-mute' => $tab !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    <div class="space-y-3">
        @forelse ($listings as $l)
            <div wire:key="listing-{{ $l->id }}" class="rounded-xl border border-rule bg-card p-4 text-sm">
                <div class="flex flex-wrap gap-x-2 text-[12px] text-mute">
                    <span class="font-semibold uppercase text-flag">{{ $l->categoryName() }}</span>
                    <span>· {{ $l->statusLabel() }}</span>
                    <span>· <a href="{{ route('admin.users.show', $l->user) }}" wire:navigate class="underline">{{ '@'.$l->user->username }}</a></span>
                    <span>· {{ $l->created_at->diffForHumans() }}</span>
                    @if ($l->paid_cents)<span>· paid ${{ number_format($l->paid_cents / 100, 2) }}</span>@endif
                    @if ($l->reports_count)<span class="font-semibold text-flag">· {{ $l->reports_count }} {{ str('report')->plural($l->reports_count) }}</span>@endif
                </div>
                <a href="{{ route('classifieds.show', $l) }}" class="font-semibold underline">{{ $l->title }}</a> @if ($l->priceLabel())<span class="text-mute">{{ $l->priceLabel() }}</span>@endif
                <p class="mt-1 whitespace-pre-line">{{ str($l->body)->limit(600) }}</p>
                @if ($l->screening)
                    <ul class="mt-1 rounded-lg bg-chip px-3 py-2 text-[12.5px]">
                        @foreach ($l->screening['watch'] ?? [] as $w)<li>Watch phrase “{{ $w['phrase'] }}”: {{ $w['meaning'] }}</li>@endforeach
                        @foreach ($l->screening['openai'] ?? [] as $category => $score)<li>OpenAI {{ $category }}: {{ $score }}</li>@endforeach
                        @if ($l->screening['openai_failed'] ?? false)<li>OpenAI check didn’t finish; held to be safe.</li>@endif
                        @if ($l->screening['reports'] ?? false)<li>Hidden by reports.</li>@endif
                    </ul>
                @endif
                <div class="mt-2 flex gap-2">
                    @if (in_array($l->status, ['held', 'removed'], true))<button type="button" wire:click="approve({{ $l->id }})" class="rounded-lg bg-flag px-3 py-1 font-semibold text-white">Approve</button>@endif
                    @if ($l->status !== 'removed')<button type="button" wire:click="remove({{ $l->id }})" wire:confirm="Take this listing down and tell the poster?" class="rounded-lg border border-rule px-3 py-1">Remove</button>@endif
                </div>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-rule p-8 text-center text-mute">Nothing here.</div>
        @endforelse
    </div>
    <div class="mt-3">{{ $listings->links() }}</div>
</div>
