<div>
    <h1 class="mb-2 font-display text-3xl font-bold">Moments</h1>
    @include('partials.admin-nav')

    @php($input = 'mt-0.5 block w-full rounded-md border border-rule bg-paper px-2 py-1')
    <section class="mb-3 flex flex-wrap items-center gap-3 rounded-xl border border-rule bg-card p-4 text-sm">
        <div class="flex-1"><b>Snow Day mode</b> <span class="text-mute">adds a winter look, a banner, and “Snow report” posts with totals and plowing by stop.</span></div>
        <button type="button" wire:click="toggleSnowDay" @class(['rounded-lg px-3 py-1.5 font-semibold', 'bg-[#009cde] text-white' => $snowDay, 'border border-rule' => ! $snowDay])>{{ $snowDay ? 'On: turn off' : 'Turn on' }}</button>
    </section>

    <div class="grid gap-3 lg:grid-cols-2">
        <form wire:submit="createEvent" class="rounded-xl border border-rule bg-card p-4 text-sm">
            <h2 class="font-semibold">New event or meetup</h2>
            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                <label class="sm:col-span-2">Name<input wire:model="event.name" placeholder="Restaurant Week, Marine Corps Marathon, Stoop Sits: Petworth" class="{{ $input }}"></label>
                <label>Kind<select wire:model.live="event.kind" class="{{ $input }}"><option value="event">City event (people post from it)</option><option value="meetup">Stoop Sits meetup (check-in code)</option></select></label>
                <label>Station<select wire:model="event.stop" class="{{ $input }}"><option value="">None</option>@foreach (collect(config('stoop.stations'))->sort() as $c => $n)<option value="{{ $c }}">{{ $n }}</option>@endforeach</select></label>
                <label>Starts (DC time)<input type="datetime-local" wire:model="event.starts_at" class="{{ $input }}"></label>
                <label>Ends (DC time)<input type="datetime-local" wire:model="event.ends_at" class="{{ $input }}"></label>
                <label>Rating label (optional)<input wire:model="event.rating_label" placeholder="Candy rating" class="{{ $input }}"></label>
                <label>Passport list (optional)<select wire:model="event.place_list" class="{{ $input }}"><option value="">None</option>@foreach ($lists as $l)<option>{{ $l }}</option>@endforeach</select></label>
                <label class="sm:col-span-2">Description<textarea wire:model="event.description" rows="2" class="{{ $input }}"></textarea></label>
            </div>
            @foreach (['event.name', 'event.starts_at', 'event.ends_at'] as $f) @error($f) <p class="text-flag">{{ $message }}</p> @enderror @endforeach
            <button class="mt-2 rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Create</button>
        </form>

        <div class="space-y-3">
            <form wire:submit="createGame" class="rounded-xl border border-rule bg-card p-4 text-sm">
                <h2 class="font-semibold">Game-day thread</h2>
                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                    <label>Team<select wire:model="game.team" class="{{ $input }}">@foreach (\App\Models\Game::TEAMS as $t)<option>{{ $t }}</option>@endforeach</select></label>
                    <label>Opponent<input wire:model="game.opponent" placeholder="Phillies" class="{{ $input }}"></label>
                    <label>Starts (DC time)<input type="datetime-local" wire:model="game.starts_at" class="{{ $input }}"></label>
                    <label class="flex items-center gap-2 pt-5"><input type="checkbox" wire:model="game.home"> Home game</label>
                </div>
                @foreach (['game.opponent', 'game.starts_at'] as $f) @error($f) <p class="text-flag">{{ $message }}</p> @enderror @endforeach
                <button class="mt-2 rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Post the thread</button>
            </form>

            <form wire:submit="createContest" class="rounded-xl border border-rule bg-card p-4 text-sm">
                <h2 class="font-semibold">Guess-the-date contest</h2>
                <div class="mt-2 grid gap-2">
                    <label>Title<input wire:model="contest.title" placeholder="Peak Bloom" class="{{ $input }}"></label>
                    <label>Question<input wire:model="contest.question" placeholder="When will the cherry blossoms hit peak bloom?" class="{{ $input }}"></label>
                    <label>Prize<input wire:model="contest.prize" placeholder="Dinner for two at a spot from our Best Restaurants list" class="{{ $input }}"></label>
                    <div class="grid grid-cols-2 gap-2">
                        <label>Guessing closes (DC time)<input type="datetime-local" wire:model="contest.closes_at" class="{{ $input }}"></label>
                        <label>Winners<input type="number" min="1" max="20" wire:model="contest.winners" class="{{ $input }}"></label>
                    </div>
                </div>
                @foreach (['contest.title', 'contest.question', 'contest.closes_at'] as $f) @error($f) <p class="text-flag">{{ $message }}</p> @enderror @endforeach
                <button class="mt-2 rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Open the contest</button>
            </form>
        </div>
    </div>

    <section class="mt-3 rounded-xl border border-rule bg-card p-4 text-sm">
        <h2 class="font-semibold">Settle up</h2>
        <ul class="mt-2 divide-y divide-rule">
            @foreach ($games->whereNull('winner')->where('starts_at', '<=', now()) as $g)
                <li class="flex flex-wrap items-center gap-2 py-2">
                    <span><b>{{ $g->team }}</b> {{ $g->home ? 'vs.' : 'at' }} {{ $g->opponent }} · {{ $g->starts_at->timezone(config('stoop.timezone'))->format('M j') }}</span>
                    <span class="ml-auto">Who won?</span>
                    <button type="button" wire:click="settleGame({{ $g->id }}, 'team')" class="rounded-lg border border-rule px-2.5 py-0.5">{{ $g->team }}</button>
                    <button type="button" wire:click="settleGame({{ $g->id }}, 'opponent')" class="rounded-lg border border-rule px-2.5 py-0.5">{{ $g->opponent }}</button>
                </li>
            @endforeach
            @foreach ($contests->whereNull('answer')->where('closes_at', '<=', now()) as $c)
                <li class="flex flex-wrap items-center gap-2 py-2">
                    <span><b>{{ $c->title }}</b> · {{ $c->entries_count }} guesses</span>
                    <input type="date" wire:model="answers.{{ $c->id }}" aria-label="Answer" class="ml-auto rounded-md border border-rule bg-paper px-2 py-0.5">
                    <button type="button" wire:click="settleContest({{ $c->id }})" class="rounded-lg border border-rule px-2.5 py-0.5">Post results</button>
                </li>
            @endforeach
        </ul>
    </section>

    <section class="mt-3 rounded-xl border border-rule bg-card p-4 text-sm">
        <h2 class="font-semibold">Events</h2>
        <ul class="mt-2 divide-y divide-rule">
            @forelse ($events as $e)
                <li class="py-2"><a href="{{ route('events.show', $e) }}" class="font-semibold underline">{{ $e->name }}</a>
                    <span class="text-mute">· {{ $e->starts_at->timezone(config('stoop.timezone'))->format('M j') }} · {{ $e->dispatches_count }} posts · {{ $e->attendees_count }} check-ins</span>
                    @if ($e->checkin_code)<span class="ml-1 rounded bg-chip px-1.5 font-mono">{{ $e->checkin_code }}</span>@endif
                </li>
            @empty
                <li class="py-2 text-mute">No events yet.</li>
            @endforelse
        </ul>
    </section>

    @if (auth()->user()->hasRole('sales'))
        <livewire:admin.meetup-sponsors />
    @endif
</div>
