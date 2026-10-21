<div>
    <h1 class="mb-2 font-display text-3xl font-bold">History</h1>
    @include('partials.admin-nav')

    @php($input = 'mt-0.5 block w-full rounded-md border border-rule bg-paper px-2 py-1')
    <form wire:submit="save" class="rounded-xl border border-rule bg-card p-4 text-sm">
        <h2 class="font-semibold">{{ $editing ? 'Edit entry' : 'Add an entry' }} <span class="font-normal text-mute">· @history posts the checked entries for today at 9am</span></h2>
        <div class="mt-2 grid gap-2 sm:grid-cols-4">
            <label>Month<select wire:model="month" class="{{ $input }}">@foreach (range(1, 12) as $m)<option value="{{ $m }}">{{ date('F', mktime(0, 0, 0, $m, 1)) }}</option>@endforeach</select></label>
            <label>Day<input type="number" min="1" max="31" wire:model="day" class="{{ $input }}"></label>
            <label>Line (optional)<select wire:model="line" class="{{ $input }}"><option value="">Blue (Culture)</option>@foreach (config('stoop.lines') as $k => $l)<option value="{{ $k }}">{{ ucfirst($k) }} ({{ $l['name'] }})</option>@endforeach</select></label>
            <label>Station (optional)<select wire:model="stop" class="{{ $input }}"><option value="">None</option>@foreach (collect(config('stoop.stations'))->sort() as $c => $n)<option value="{{ $c }}">{{ $n }}</option>@endforeach</select></label>
            <label class="sm:col-span-4">Text<textarea wire:model="text" rows="2" maxlength="400" placeholder="1976: Metrorail opens, with Red Line trains between Farragut North and Rhode Island Ave." class="{{ $input }}"></textarea></label>
            <label class="sm:col-span-4">Link (optional)<input wire:model="link" maxlength="300" placeholder="https://" class="{{ $input }}"></label>
        </div>
        @foreach (['month', 'day', 'text', 'link', 'line', 'stop'] as $f) @error($f) <p class="text-flag">{{ $message }}</p> @enderror @endforeach
        <div class="mt-2 flex flex-wrap items-center gap-2">
            <button class="rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">{{ $editing ? 'Save changes' : 'Add entry' }}</button>
            @if ($editing)<button type="button" wire:click="cancel" class="rounded-lg border border-rule px-3 py-1.5">Cancel</button>@endif
            <button type="button" wire:click="draft" @disabled(! $ai) class="rounded-lg border border-rule px-3 py-1.5 disabled:opacity-50">Draft with AI</button>
            <span class="text-[12px] text-mute">{{ $ai ? 'Fills in the text from the date (type a topic first to steer it). Check every fact before saving.' : 'Needs ANTHROPIC_API_KEY' }}</span>
        </div>
    </form>

    <ul class="mt-3 divide-y divide-rule rounded-xl border border-rule bg-card text-sm">
        @forelse ($entries as $e)
            <li class="flex items-start gap-3 px-4 py-2.5">
                <span class="w-16 shrink-0 font-semibold">{{ date('M', mktime(0, 0, 0, $e->month, 1)) }} {{ $e->day }}</span>
                <div class="min-w-0 flex-1">
                    <p>{{ $e->text }} @if ($e->verify)<span class="ml-1 rounded bg-flag px-1 text-[10px] font-bold text-white">VERIFY</span>@endif</p>
                    @if ($e->link)<a href="{{ $e->link }}" class="text-mute underline" rel="noopener" target="_blank">{{ $e->link }}</a>@endif
                    @if ($e->stop)<span class="text-mute">· {{ config('stoop.stations.'.$e->stop) }}</span>@endif
                </div>
                @if ($e->verify)<button type="button" wire:click="verify({{ $e->id }})" class="rounded-lg border border-rule px-2.5 py-0.5">Checked</button>@endif
                <button type="button" wire:click="edit({{ $e->id }})" class="rounded-lg border border-rule px-2.5 py-0.5">Edit</button>
                <button type="button" wire:click="delete({{ $e->id }})" wire:confirm="Delete this entry?" class="rounded-lg border border-rule px-2.5 py-0.5">Delete</button>
            </li>
        @empty
            <li class="px-4 py-2.5 text-mute">No entries yet.</li>
        @endforelse
    </ul>
</div>
