<div>
    <h1 class="mb-2 font-display text-3xl font-bold">Ads</h1>
    @include('partials.admin-nav')
    @php($input = 'mt-0.5 block w-full rounded-md border border-rule bg-paper px-2 py-1')
    <p class="text-sm text-mute">Sponsored dispatches show in feeds, at most one per {{ \App\Support\Ads::EVERY }} posts, labeled “Sponsored · advertiser”. They’re matched only to the stop or topic being read, never to the reader, and never appear in threads, notifications, messages, rankings, points or the moderation queue. An impression counts once per person per day.</p>

    @unless (\App\Support\Stripe::configured())<p class="mt-2 rounded-lg bg-chip p-2 text-sm">Stripe not configured: self-serve promotions and Business upgrades are hidden. Set test-mode <code>STRIPE_SECRET</code> and <code>STRIPE_WEBHOOK_SECRET</code> (see the README).</p>@endunless
    <livewire:admin.promotion-queue />

    <div class="mt-3 flex flex-wrap gap-2 text-sm">
        <button type="button" wire:click="edit" class="rounded-lg border border-rule px-3 py-1.5">New sponsored dispatch</button>
        <button type="button" wire:click="editParty" class="rounded-lg border border-rule px-3 py-1.5">Party tickets for a ballot</button>
        <a href="{{ route('admin.ads.csv') }}" class="rounded-lg border border-rule px-3 py-1.5">Inventory CSV</a>
    </div>

    @if ($editing !== null)
        <form wire:submit="save" class="mt-3 grid gap-2 rounded-xl border border-ink bg-card p-4 text-sm sm:grid-cols-2">
            <label>Advertiser<input wire:model="form.advertiser" maxlength="120" class="{{ $input }}">@error('form.advertiser')<span class="text-flag">{{ $message }}</span>@enderror</label>
            <label>Link<input wire:model="form.url" placeholder="https://" class="{{ $input }}">@error('form.url')<span class="text-flag">{{ $message }}</span>@enderror</label>
            <label class="sm:col-span-2">Text<textarea wire:model="form.body" maxlength="500" rows="3" class="{{ $input }}"></textarea>@error('form.body')<span class="text-flag">{{ $message }}</span>@enderror</label>
            <label>Image (optional)<input type="file" wire:model="image" accept="image/*" class="mt-0.5 block w-full">@error('image')<span class="text-flag">{{ $message }}</span>@enderror</label>
            <div></div>
            <label>Stations (none and no topics means everywhere)
                <select multiple wire:model="form.stops" size="5" class="{{ $input }}">@foreach (config('stoop.stations') as $code => $n)<option value="{{ $code }}">{{ $code }} · {{ $n }}</option>@endforeach</select>
                @error('form.stops.*')<span class="text-flag">{{ $message }}</span>@enderror
            </label>
            <fieldset><legend>Topics</legend>
                @foreach (config('stoop.lines') as $key => $l)
                    <label class="mr-3 inline-flex items-center gap-1"><input type="checkbox" wire:model="form.lines" value="{{ $key }}"> {{ $l['name'] }}</label>
                @endforeach
            </fieldset>
            <label>Starts (DC time)<input type="datetime-local" wire:model="form.starts_at" class="{{ $input }}"></label>
            <label>Ends<input type="datetime-local" wire:model="form.ends_at" class="{{ $input }}">@error('form.ends_at')<span class="text-flag">{{ $message }}</span>@enderror</label>
            <label>Times one person sees it per day<input type="number" min="1" max="50" wire:model="form.daily_cap" class="{{ $input }}">@error('form.daily_cap')<span class="text-flag">{{ $message }}</span>@enderror</label>
            <label>Impression goal (optional; stops when reached)<input type="number" min="1" wire:model="form.goal" class="{{ $input }}">@error('form.goal')<span class="text-flag">{{ $message }}</span>@enderror</label>
            <div class="flex gap-2 sm:col-span-2"><button class="rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Save</button><button type="button" wire:click="$set('editing', null)" class="rounded-lg border border-rule px-3 py-1.5">Cancel</button></div>
        </form>
    @endif

    @if ($editingParty !== null)
        <form wire:submit="saveParty" class="mt-3 grid gap-2 rounded-xl border border-ink bg-card p-4 text-sm sm:grid-cols-2">
            <p class="text-mute sm:col-span-2">Shown on the ballot page and as a “From the magazine” card in feeds from when voting opens until voting closes or the party, whichever is later. Tickets are sold on the outside link; Stoop takes no payments.</p>
            <label>Ballot<select wire:model="party.ballot_id" class="{{ $input }}"><option value="">Pick one</option>@foreach ($ballots as $b)<option value="{{ $b->id }}">{{ $b->title }}</option>@endforeach</select>@error('party.ballot_id')<span class="text-flag">{{ $message }}</span>@enderror</label>
            <label>Title<input wire:model="party.title" maxlength="160" placeholder="Best of Washington Party" class="{{ $input }}">@error('party.title')<span class="text-flag">{{ $message }}</span>@enderror</label>
            <label>Date<input type="date" wire:model="party.event_on" class="{{ $input }}">@error('party.event_on')<span class="text-flag">{{ $message }}</span>@enderror</label>
            <label>Venue<input wire:model="party.venue" maxlength="160" class="{{ $input }}">@error('party.venue')<span class="text-flag">{{ $message }}</span>@enderror</label>
            <label>Ticket link<input wire:model="party.url" placeholder="https://" class="{{ $input }}">@error('party.url')<span class="text-flag">{{ $message }}</span>@enderror</label>
            <label>Price (as shown)<input wire:model="party.price" maxlength="60" placeholder="From $85" class="{{ $input }}"></label>
            <div class="flex gap-2 sm:col-span-2"><button class="rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Save</button><button type="button" wire:click="$set('editingParty', null)" class="rounded-lg border border-rule px-3 py-1.5">Cancel</button></div>
        </form>
    @endif

    @if ($reportAd)
        <section class="mt-3 rounded-xl border border-ink bg-card p-4 text-sm">
            <div class="flex items-center justify-between gap-2">
                <h2 class="font-display text-xl font-bold">Daily report: {{ $reportAd->advertiser ?? $reportAd->sponsor ?? $reportAd->title }}</h2>
                <div class="flex gap-2"><a href="{{ route('admin.ads.report', explode(':', $report)) }}" class="rounded-lg border border-rule px-2.5 py-0.5">CSV</a><button type="button" wire:click="$set('report', '')" class="rounded-lg border border-rule px-2.5 py-0.5">Close</button></div>
            </div>
            @if ($reportReasons->isNotEmpty())
                <p class="mt-1 text-flag">Reports: @foreach ($reportReasons as $reason => $n){{ \App\Support\Ads::REPORT_REASONS[$reason] ?? $reason }} ({{ $n }}){{ $loop->last ? '' : ', ' }}@endforeach</p>
            @endif
            <table class="mt-2 w-full tabular-nums">
                <thead class="text-left text-[11px] uppercase tracking-wide text-mute"><tr><th class="py-1">Day</th><th>Impressions</th><th>Clicks</th><th>CTR</th></tr></thead>
                <tbody class="divide-y divide-rule">
                    @forelse ($reportRows as $r)
                        <tr><td class="py-1">{{ $r->day }}</td><td>{{ number_format($r->impressions) }}</td><td>{{ number_format($r->clicks) }}</td><td>{{ $r->ctr !== null ? $r->ctr.'%' : '—' }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="py-3 text-center text-mute">No impressions yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    @endif

    <h2 class="mt-5 font-display text-xl font-bold">Running and upcoming</h2>
    <div class="mt-2 overflow-x-auto rounded-xl border border-rule bg-card">
        <table class="w-full text-sm">
            <thead class="text-left text-[11px] uppercase tracking-wide text-mute"><tr><th class="px-4 py-2">Placement</th><th class="px-4 py-2">Where</th><th class="px-4 py-2">Dates</th><th class="px-4 py-2">Delivered</th><th class="px-4 py-2">Clicks</th><th class="px-4 py-2">Hidden</th><th></th></tr></thead>
            <tbody class="divide-y divide-rule">
                @forelse ($inventory as $r)
                    <tr wire:key="inv-{{ $r['kind'] }}-{{ $r['id'] }}">
                        <td class="px-4 py-2"><div class="text-[11px] uppercase tracking-wide text-mute">{{ $r['type'] }}</div><b>{{ $r['name'] }}</b></td>
                        <td class="px-4 py-2">{{ $r['where'] }}</td>
                        <td class="whitespace-nowrap px-4 py-2">{{ $r['starts'] ?? '—' }} to {{ $r['ends'] ?? '—' }} @if ($r['status'] === 'live')<span class="rounded bg-flag px-1 text-[10px] font-bold text-white">LIVE</span>@else<span class="rounded border border-rule px-1 text-[10px] font-bold text-mute">UPCOMING</span>@endif</td>
                        <td class="px-4 py-2 tabular-nums">{{ $r['impressions'] === null ? '—' : number_format($r['impressions']) }}@if ($r['goal']) <span class="text-mute">of {{ number_format($r['goal']) }} ({{ round($r['impressions'] / $r['goal'] * 100) }}%)</span>@endif</td>
                        <td class="px-4 py-2 tabular-nums">{{ $r['clicks'] === null ? '—' : number_format($r['clicks']) }} @if ($r['ctr'] !== null)<span class="text-mute">{{ $r['ctr'] }}%</span>@endif</td>
                        <td class="px-4 py-2 tabular-nums">{{ $r['hides'] ?: '' }}@if ($r['reports']) <span class="text-flag">{{ $r['reports'] }} {{ str('report')->plural($r['reports']) }}</span>@endif</td>
                        <td class="whitespace-nowrap px-4 py-2 text-right">
                            @if ($r['has_report'])<button type="button" wire:click="$set('report', '{{ $r['kind'] }}:{{ $r['id'] }}')" class="underline">Report</button>@endif
                            @if ($r['kind'] === 'dispatch')<button type="button" wire:click="edit({{ $r['id'] }})" class="ml-2 underline">Edit</button> <button type="button" wire:click="end('dispatch', {{ $r['id'] }})" wire:confirm="End this ad now?" class="ml-2 underline">End now</button>@endif
                            @if ($r['kind'] === 'party')<button type="button" wire:click="editParty({{ $r['id'] }})" class="ml-2 underline">Edit</button>@endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="p-6 text-center text-mute">Nothing running or booked.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
