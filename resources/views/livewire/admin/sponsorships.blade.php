<div>
    <h1 class="mb-2 font-display text-3xl font-bold">Sponsorships</h1>
    @include('partials.admin-nav')
    <p class="text-sm text-mute">Sponsored cards show at the top of a station’s or line’s feed, always labeled “Sponsored”. They never change rankings, Been here counts or poll results. A view counts once per person per day; daily reports are under Report.</p>

    <button type="button" wire:click="edit" class="mt-3 rounded-lg border border-rule px-3 py-1.5 text-sm">New sponsorship</button>

    @if ($editing !== null)
        <form wire:submit="save" class="mt-3 grid gap-2 rounded-xl border border-ink bg-card p-4 text-sm sm:grid-cols-2">
            <label>Sponsor<input wire:model="form.sponsor" class="mt-0.5 block w-full rounded-md border border-rule bg-paper px-2 py-1">@error('form.sponsor')<span class="text-flag">{{ $message }}</span>@enderror</label>
            <label>Headline<input wire:model="form.headline" maxlength="120" class="mt-0.5 block w-full rounded-md border border-rule bg-paper px-2 py-1">@error('form.headline')<span class="text-flag">{{ $message }}</span>@enderror</label>
            <label>Station<select wire:model="form.stop" class="mt-0.5 block w-full rounded-md border border-rule bg-paper px-2 py-1"><option value="">None</option>@foreach (config('stoop.stations') as $code => $n)<option value="{{ $code }}">{{ $code }} · {{ $n }}</option>@endforeach</select>@error('form.stop')<span class="text-flag">{{ $message }}</span>@enderror</label>
            <label>Line<select wire:model="form.line" class="mt-0.5 block w-full rounded-md border border-rule bg-paper px-2 py-1"><option value="">None</option>@foreach (config('stoop.lines') as $key => $l)<option value="{{ $key }}">{{ $l['name'] }}</option>@endforeach</select></label>
            <label class="sm:col-span-2">Text (optional)<input wire:model="form.body" maxlength="280" class="mt-0.5 block w-full rounded-md border border-rule bg-paper px-2 py-1"></label>
            <label>Link<input wire:model="form.url" placeholder="https://" class="mt-0.5 block w-full rounded-md border border-rule bg-paper px-2 py-1">@error('form.url')<span class="text-flag">{{ $message }}</span>@enderror</label>
            <label>Place ID (optional)<input wire:model="form.place_id" class="mt-0.5 block w-full rounded-md border border-rule bg-paper px-2 py-1"></label>
            <label>Starts<input type="date" wire:model="form.starts_on" class="mt-0.5 block w-full rounded-md border border-rule bg-paper px-2 py-1"></label>
            <label>Ends<input type="date" wire:model="form.ends_on" class="mt-0.5 block w-full rounded-md border border-rule bg-paper px-2 py-1">@error('form.ends_on')<span class="text-flag">{{ $message }}</span>@enderror</label>
            <div class="flex gap-2 sm:col-span-2"><button class="rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Save</button><button type="button" wire:click="$set('editing', null)" class="rounded-lg border border-rule px-3 py-1.5">Cancel</button></div>
        </form>
    @endif

    <div class="mt-3 overflow-x-auto rounded-xl border border-rule bg-card">
        <table class="w-full text-sm">
            <thead class="text-left text-[11px] uppercase tracking-wide text-mute"><tr><th class="px-4 py-2">Sponsor</th><th class="px-4 py-2">Where</th><th class="px-4 py-2">Dates</th><th class="px-4 py-2">Views</th><th class="px-4 py-2">Clicks</th><th></th></tr></thead>
            <tbody class="divide-y divide-rule">
                @forelse ($sponsorships as $s)
                    @php($today = now(config('stoop.timezone'))->toDateString())
                    @php($running = $s->starts_on->toDateString() <= $today && $s->ends_on->toDateString() >= $today)
                    <tr wire:key="s-{{ $s->id }}">
                        <td class="px-4 py-2"><b>{{ $s->sponsor }}</b><div class="text-mute">{{ $s->headline }}</div></td>
                        <td class="px-4 py-2">{{ $s->stop ? $s->stop.' · '.config("stoop.stations.{$s->stop}") : config("stoop.lines.{$s->line}.name").' line' }}</td>
                        <td class="px-4 py-2">{{ $s->starts_on->format('M j') }} to {{ $s->ends_on->format('M j, Y') }} @if ($running)<span class="rounded bg-flag px-1 text-[10px] font-bold text-white">LIVE</span>@endif</td>
                        <td class="px-4 py-2 tabular-nums">{{ number_format($s->impressions) }}</td>
                        <td class="px-4 py-2 tabular-nums">{{ number_format($s->clicks) }} <span class="text-mute">{{ $s->impressions ? round($s->clicks / $s->impressions * 100, 1).'%' : '' }}</span></td>
                        <td class="whitespace-nowrap px-4 py-2 text-right"><a href="{{ route('admin.ads', ['report' => 'sponsorship:'.$s->id]) }}" wire:navigate class="underline">Report</a> <button type="button" wire:click="edit({{ $s->id }})" class="ml-2 underline">Edit</button> @if ($running)<button type="button" wire:click="end({{ $s->id }})" class="ml-2 underline">End now</button>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="p-6 text-center text-mute">No sponsorships yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
