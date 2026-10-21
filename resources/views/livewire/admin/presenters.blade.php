<section class="mt-5">
    @php($input = 'mt-0.5 block w-full rounded-md border border-rule bg-paper px-2 py-1')
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="font-display text-xl font-bold">Presented by</h2>
        <button type="button" wire:click="edit" class="rounded-lg border border-rule px-3 py-1.5 text-sm">New bot or game sponsor</button>
    </div>
    <p class="text-sm text-mute">A sponsor’s name (and logo on games) on a house bot or a game. Bots add one line, “Presented by …”, to their scheduled posts, never to replies or safety posts (@metro incidents, @weather alerts), and only when it fits in {{ config('stoop.max_length') }} characters. Games show it on their page and Play card. One sponsor per bot or game at a time.</p>

    @if ($editing !== null)
        <form wire:submit="save" class="mt-3 grid gap-2 rounded-xl border border-ink bg-card p-4 text-sm sm:grid-cols-2">
            <label>Bot or game<select wire:model="form.target" class="{{ $input }}"><option value="">Pick one</option>@foreach ($targets as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>@error('form.target')<span class="text-flag">{{ $message }}</span>@enderror</label>
            <label>Sponsor name<input wire:model="form.sponsor" maxlength="40" class="{{ $input }}">@error('form.sponsor')<span class="text-flag">{{ $message }}</span>@enderror</label>
            <label>Link<input wire:model="form.url" placeholder="https://" class="{{ $input }}">@error('form.url')<span class="text-flag">{{ $message }}</span>@enderror</label>
            <label>Logo (optional, games only)<input type="file" wire:model="logo" accept="image/*" class="mt-0.5 block w-full">@error('logo')<span class="text-flag">{{ $message }}</span>@enderror</label>
            <label>Starts<input type="date" wire:model="form.starts_on" class="{{ $input }}"></label>
            <label>Ends<input type="date" wire:model="form.ends_on" class="{{ $input }}">@error('form.ends_on')<span class="text-flag">{{ $message }}</span>@enderror</label>
            <div class="flex gap-2 sm:col-span-2"><button class="rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Save</button><button type="button" wire:click="$set('editing', null)" class="rounded-lg border border-rule px-3 py-1.5">Cancel</button></div>
        </form>
    @endif

    @if ($presenters->isNotEmpty())
        <ul class="mt-2 divide-y divide-rule rounded-xl border border-rule bg-card text-sm">
            @foreach ($presenters as $p)
                <li wire:key="presenter-{{ $p->id }}" class="flex flex-wrap items-center gap-2 px-4 py-2">
                    <b>{{ $p->sponsor }}</b> <span class="text-mute">on {{ $p->targetLabel() }} · {{ $p->starts_on->format('M j') }} to {{ $p->ends_on->format('M j') }}</span>
                    <span class="ml-auto whitespace-nowrap">
                        <a href="{{ route('admin.ads', ['report' => 'presenter:'.$p->id]) }}" class="underline">Report</a>
                        <button type="button" wire:click="edit({{ $p->id }})" class="ml-2 underline">Edit</button>
                        <button type="button" wire:click="end({{ $p->id }})" wire:confirm="End this sponsorship now?" class="ml-2 underline">End</button>
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</section>
