{{-- Happening and Lost & found details on a dispatch card. Expects $d, $marks, $focus and $me. --}}
@if ($d->type === 'happening' && ! $d->parent_id)
    @php($h = $d->happening)
    <div class="mt-2 rounded-lg border border-rule px-3 py-2 text-sm text-mute sm:ml-[46px]">
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
            @if ($h?->featured)<span class="rounded bg-flag px-1 text-[10px] font-bold text-white">FEATURED</span>@endif
            @if ($d->happening_at)<b class="text-ink">{{ $d->happening_at }}</b>@endif
            <span>· {{ $d->going_count }} going · {{ \App\Support\Happenings::counts($d)['interested'] }} interested</span>
            <button type="button" wire:click="toggleMark({{ $d->id }}, 'interested')" @class(['ml-auto rounded-full border px-2.5 py-0.5 text-[12.5px]', 'border-ink font-semibold text-ink' => in_array('interested', $marks), 'border-rule' => ! in_array('interested', $marks)]) aria-pressed="{{ in_array('interested', $marks) ? 'true' : 'false' }}">Interested</button>
            @if ($h?->starts_at)<a href="{{ route('happenings.ics', $d) }}" class="text-[12.5px] underline">Add to calendar</a>@endif
        </div>
        @if ($h?->isTicketed())
            <div class="mt-1.5 flex flex-wrap items-center gap-2">
                <span class="rounded border border-rule px-1 text-[10px] font-bold">TICKETED{{ $h->priceLabel() ? ' · '.$h->priceLabel() : '' }}</span>
                <a href="{{ route('happenings.tickets', $d) }}" target="_blank" rel="{{ \App\Support\Referrals::rel($h->ticket_url, 'tickets') }}" class="rounded-lg bg-ink px-2.5 py-0.5 text-[12.5px] font-semibold text-paper">Get tickets</a>
                <span class="text-[12px]">Sold by the host on their own site.@if (\App\Support\Referrals::earns($h->ticket_url, 'tickets')) {{ \App\Support\Referrals::COMMISSION }}.@endif</span>
            </div>
        @endif
    </div>
    @if ($focus && $me && ($me->id === $d->user_id || $me->is_staff))
        <livewire:happening-host :dispatch-id="$d->id" :key="'host-'.$d->id" />
    @endif
@endif

@if ($d->type === 'lost' && ! $d->parent_id && $d->details)
    @php($reunited = (bool) ($d->details['reunited_at'] ?? null))
    <div @class(['mt-2 flex flex-wrap items-center gap-2 rounded-lg border px-3 py-2 text-sm sm:ml-[46px]', 'border-[#0b7a33]/40 bg-[#0b7a33]/5' => $reunited, 'border-rule' => ! $reunited])>
        <b @class(['text-[#0b7a33] dark:text-[#4cc77a]' => $reunited])>{{ $reunited ? 'Reunited' : \App\Support\LostAndFound::label($d->details) }}</b>
        @if ($d->stop)<span class="text-mute">· last seen near {{ $d->stopName() }}</span>@endif
        @if (! $reunited && $me?->id === $d->user_id)
            <button type="button" wire:click="markReunited({{ $d->id }})" wire:confirm="Mark this as reunited? It closes the post." class="ml-auto rounded-full border border-rule px-2.5 py-0.5 text-[12.5px]">Mark reunited</button>
        @elseif (! $reunited)
            <span class="ml-auto text-[12.5px] text-mute">Seen it? Reply, or message {{ '@'.$d->user->username }}.</span>
        @endif
    </div>
@endif
