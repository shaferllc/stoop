<div>
    @if ($meetups->isNotEmpty())
    <section class="rounded-xl border border-rule bg-card p-4 text-sm">
        <h2 class="text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">Meetups you host</h2>
        <table class="mt-2 w-full tabular-nums">
            <thead class="text-left text-[11px] uppercase tracking-wide text-mute"><tr><th class="py-1">Meetup</th><th>Going</th><th>Checked in</th><th>Redeemed</th></tr></thead>
            <tbody class="divide-y divide-rule">
                @foreach ($meetups as $m)
                    <tr wire:key="hosted-{{ $m->id }}"><td class="py-1"><a href="{{ route('events.show', $m->event) }}" wire:navigate class="underline">{{ $m->event->name }}</a> <span class="text-mute">{{ $m->event->starts_at->timezone(config('stoop.timezone'))->format('M j') }}</span></td>
                        <td>{{ $m->event->rsvps_count }}</td><td>{{ $m->event->attendees_count }}</td><td>{{ $m->offer ? $m->redeemed_count : '—' }}</td></tr>
                @endforeach
            </tbody>
        </table>

        <form wire:submit="redeem" class="mt-3 flex flex-wrap items-center gap-2">
            <label for="redeem-code">Redeem an offer code</label>
            <input id="redeem-code" wire:model="code" maxlength="12" placeholder="Code" class="w-36 rounded-md border border-rule bg-paper px-2.5 py-1.5 font-mono uppercase">
            <button class="rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Mark used</button>
        </form>
        @error('code') <p class="mt-1 text-flag">{{ $message }}</p> @enderror
    </section>
    @endif
</div>
