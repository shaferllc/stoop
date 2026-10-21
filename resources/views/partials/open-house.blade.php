{{-- An open house on a dispatch card: the block, when, price, listing link and the listing agent, labeled. --}}
@php($oh = $d->details)
<div class="mt-2 rounded-lg border border-rule px-3 py-2 text-[13px] sm:ml-[46px]">
    @if ($oh['sponsored'] ?? false)
        <div class="mb-1 text-[11.5px] font-semibold uppercase tracking-wide text-mute">Sponsored</div>
    @endif
    <div class="flex flex-wrap items-baseline justify-between gap-x-3">
        <b>{{ $oh['block'] ?? '' }}</b>
        @isset($oh['price'])<b class="tabular-nums">${{ number_format($oh['price']) }}</b>@endisset
    </div>
    @isset($oh['date'])
        <div class="text-mute">Open house · {{ \Illuminate\Support\Carbon::parse($oh['date'])->format('D, M j') }} · {{ \Illuminate\Support\Carbon::parse($oh['from'])->format('g:ia') }}–{{ \Illuminate\Support\Carbon::parse($oh['to'])->format('g:ia') }}</div>
    @endisset
    @isset($oh['agent'])<div><span class="text-mute">Listing agent:</span> {{ $oh['agent'] }}</div>@endisset
    @isset($oh['url'])
        <a href="{{ $oh['url'] }}" target="_blank" rel="{{ ($oh['sponsored'] ?? false) ? 'sponsored' : 'nofollow ugc' }} noopener" class="underline">See the listing</a>
    @endisset
</div>
