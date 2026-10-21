<div>
    <h1 class="mb-2 font-display text-3xl font-bold">Scavenger hunts</h1>
    @include('partials.admin-nav')

    @php($input = 'mt-0.5 block w-full rounded-md border border-rule bg-paper px-2 py-1')
    <form wire:submit="create" class="rounded-xl border border-rule bg-card p-4 text-sm">
        <h2 class="font-semibold">New hunt</h2>
        <p class="text-mute">Players finish a step by posting a Been here or a dispatch at the place (or the station, if there’s no place) and typing the code word, if there is one. Each step is a passport stamp.</p>
        <div class="mt-2 grid gap-2 sm:grid-cols-2">
            <label class="sm:col-span-2">Name<input wire:model="hunt.name" placeholder="Cherry Blossom Hunt" class="{{ $input }}"></label>
            <label>Starts (DC time)<input type="datetime-local" wire:model="hunt.starts_at" class="{{ $input }}"></label>
            <label>Ends (DC time)<input type="datetime-local" wire:model="hunt.ends_at" class="{{ $input }}"></label>
            <label class="sm:col-span-2">Description<textarea wire:model="hunt.description" rows="2" class="{{ $input }}"></textarea></label>
        </div>

        <h3 class="mt-3 font-semibold">Clues</h3>
        @foreach ($steps as $i => $step)
            <div wire:key="step-{{ $i }}" class="mt-2 grid gap-2 rounded-lg border border-rule p-2 sm:grid-cols-4">
                <label class="sm:col-span-4">Clue {{ $i + 1 }}<input wire:model="steps.{{ $i }}.clue" maxlength="300" placeholder="Where the Godfather of Go-Go’s mural watches over the U Street crowd" class="{{ $input }}"></label>
                <label>Station<select wire:model="steps.{{ $i }}.stop" class="{{ $input }}"><option value="">None</option>@foreach (collect(config('stoop.stations'))->sort() as $c => $n)<option value="{{ $c }}">{{ $n }}</option>@endforeach</select></label>
                <label>Or a place<select wire:model="steps.{{ $i }}.place_id" class="{{ $input }}"><option value="">None</option>@foreach ($places as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></label>
                <label>Code word (optional)<input wire:model="steps.{{ $i }}.code_word" maxlength="40" class="{{ $input }}"></label>
                <div class="flex items-end">@if (count($steps) > 1)<button type="button" wire:click="removeStep({{ $i }})" class="text-mute underline">Remove</button>@endif</div>
                @foreach (["steps.$i.clue", "steps.$i.stop", "steps.$i.place_id"] as $f) @error($f) <p class="text-flag sm:col-span-4">{{ $message }}</p> @enderror @endforeach
            </div>
        @endforeach
        <button type="button" wire:click="addStep" class="mt-2 rounded-lg border border-rule px-3 py-1">Add a clue</button>

        @foreach (['hunt.name', 'hunt.starts_at', 'hunt.ends_at', 'steps'] as $f) @error($f) <p class="text-flag">{{ $message }}</p> @enderror @endforeach
        <div><button class="mt-3 rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Create the hunt</button></div>
    </form>

    <section class="mt-3 rounded-xl border border-rule bg-card p-4 text-sm">
        <h2 class="font-semibold">Hunts</h2>
        <ul class="mt-2 divide-y divide-rule">
            @forelse ($hunts as $h)
                <li class="py-2">
                    <a href="{{ route('hunts.show', $h) }}" class="font-semibold underline">{{ $h->name }}</a>
                    <span class="text-mute">· {{ $h->starts_at->timezone(config('stoop.timezone'))->format('M j') }}–{{ $h->ends_at->timezone(config('stoop.timezone'))->format('M j') }} · {{ $players[$h->id] ?? 0 }} players</span>
                    <ol class="mt-1 list-decimal pl-5 text-mute">
                        @foreach ($h->steps as $s)
                            <li>{{ $s->clue }} → <b class="text-ink">{{ $s->place?->name ?? config('stoop.stations.'.$s->stop) }}</b>@if ($s->code_word) · code <span class="font-mono">{{ $s->code_word }}</span>@endif</li>
                        @endforeach
                    </ol>
                </li>
            @empty
                <li class="py-2 text-mute">No hunts yet.</li>
            @endforelse
        </ul>
    </section>
</div>
