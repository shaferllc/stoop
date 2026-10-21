<section class="rounded-xl border border-rule bg-card p-4 text-sm">
    <h2 class="font-semibold">Bot and spam check <span class="font-normal text-mute">· staff only</span></h2>
    <p class="mt-1">Sign-up risk <b @class(['tabular-nums', 'text-flag' => $user->risk_score >= config('stoop.signup_risk_hold')])>{{ $user->risk_score }}</b>/100
        @if ($user->risk_reasons) · {{ implode(', ', $user->risk_reasons) }}@endif
        @if ($held)<span class="ml-1 rounded bg-flag px-1.5 text-[11px] font-bold text-white">FIRST POSTS HELD</span>@endif
    </p>
    @foreach ($clusters as $c)
        <p class="mt-1"><a href="{{ route('admin.spam') }}" wire:navigate class="text-flag underline">In a spam cluster:</a> {{ $c->summary }}</p>
    @endforeach

    <div class="mt-3 flex flex-wrap items-center gap-2">
        <button type="button" wire:click="check" wire:loading.attr="disabled" class="rounded-lg border border-rule px-3 py-1">Is this a bot or a spam ring?</button>
        <span class="text-[12px] text-mute">{{ $claude ? 'Asks Claude about recent activity.' : 'Needs ANTHROPIC_API_KEY for Claude; uses the built-in signals for now.' }}</span>
    </div>
    @if ($result)
        <div class="mt-2 rounded-lg bg-chip p-3">
            <p><b>{{ $result['label'] }}</b> <span class="text-mute">· {{ $result['confidence'] }}% sure · {{ $result['source'] === 'claude' ? 'Claude' : 'built-in signals' }}</span></p>
            <ul class="mt-1 list-disc pl-5">@foreach ($result['reasons'] as $r)<li>{{ $r }}</li>@endforeach</ul>
            <p class="mt-1 text-[12px] text-mute">A suggestion only. Nothing happens to the account unless you act.</p>
        </div>
    @endif
</section>
