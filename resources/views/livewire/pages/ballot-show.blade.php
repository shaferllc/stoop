<div class="space-y-3">
    @php($tz = config('stoop.timezone'))
    <section class="rounded-xl border border-rule bg-card p-6">
        <div class="text-[11.5px] font-semibold uppercase tracking-wide text-flag">Reader vote</div>
        <h1 class="font-display text-3xl font-bold">{{ $ballot->title }}</h1>
        <p class="mt-1 text-sm text-mute">
            @if ($ballot->isOpen())
                One vote per category. Voting closes {{ $ballot->closes_at->timezone($tz)->format('D M j, g:ia') }}; results show after that.
            @elseif ($ballot->isClosed())
                Voting closed {{ $ballot->closes_at->timezone($tz)->format('M j') }}. Here’s what readers picked.
            @else
                Voting opens {{ $ballot->opens_at->timezone($tz)->format('D M j, g:ia') }}.
            @endif
        </p>
        @guest
            @if ($ballot->isOpen())<p class="mt-2 text-sm"><a href="{{ route('login') }}" class="underline">Log in</a> to vote.</p>@endif
        @endguest
    </section>

    @include('partials.ballot-party')

    @foreach ($categories as $c)
        <section wire:key="cat-{{ $c->id }}" class="rounded-xl border border-rule bg-card p-4">
            <h2 class="font-display text-xl font-bold">{{ $c->name }}</h2>
            @if ($c->winner)<p class="text-sm font-semibold text-flag">Winner: {{ $c->winner->name }}</p>@endif
            @php($total = $c->nominees->sum('votes'))
            <ul class="mt-2 space-y-1.5">
                @foreach ($c->nominees as $n)
                    <li wire:key="nominee-{{ $n->id }}" @class(['relative flex items-center justify-between gap-2 overflow-hidden rounded-lg border px-3 py-1.5 text-sm', 'border-ink font-semibold' => $c->pick === $n->id, 'border-rule' => $c->pick !== $n->id])>
                        @if ($n->votes !== null)<i class="absolute inset-y-0 left-0 bg-chip" style="width: {{ $total ? round($n->votes / $total * 100) : 0 }}%"></i>@endif
                        <a href="{{ route('places.show', $n->slug) }}" wire:navigate class="relative hover:underline">{{ $n->name }}@if ($n->neighborhood) <span class="font-normal text-mute">· {{ $n->neighborhood }}</span>@endif{{ $c->pick === $n->id ? ' ✓' : '' }}</a>
                        @if ($n->votes !== null)
                            <span class="relative tabular-nums text-mute">{{ number_format($n->votes) }} {{ str('vote')->plural($n->votes) }}</span>
                        @elseif ($ballot->isOpen() && auth()->check() && ! $c->pick)
                            <button type="button" wire:click="vote({{ $n->id }})" wire:confirm="Vote for {{ $n->name }}? You get one vote in this category." class="relative rounded-full border border-rule px-2.5 py-0.5 text-[12.5px] hover:border-ink">Vote</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach
</div>
