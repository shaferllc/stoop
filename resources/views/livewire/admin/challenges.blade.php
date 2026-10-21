@php($tz = config('stoop.timezone'))
<div>
    <h1 class="mb-2 font-display text-3xl font-bold">List challenges</h1>
    @include('partials.admin-nav')

    @php($input = 'mt-0.5 block w-full rounded-md border border-rule bg-paper px-2 py-1')
    <form wire:submit="create" class="rounded-xl border border-rule bg-card p-4 text-sm">
        <h2 class="font-semibold">New challenge</h2>
        <p class="text-mute">Players count a place when they tap Been here on it (or post a Been here there) between the start and the end. Reaching the goal earns the badge and {{ \App\Support\Challenges::XP }} XP. Prizes are sent by hand from the finishers list.</p>
        <div class="mt-2 grid gap-2 sm:grid-cols-2">
            <label>List<select wire:model.live="form.list_name" class="{{ $input }}"><option value="">Pick a list</option>@foreach ($lists as $l)<option value="{{ $l->list_name }}">{{ $l->list_name }} ({{ $l->size }})</option>@endforeach</select></label>
            <label>Goal (places)<input type="number" min="1" wire:model="form.goal" class="{{ $input }}"></label>
            <label class="sm:col-span-2">Title (optional)<input wire:model="form.title" maxlength="160" placeholder="Eat at 10 of the 100 Very Best" class="{{ $input }}"></label>
            <label>Starts (DC time)<input type="datetime-local" wire:model="form.starts_at" class="{{ $input }}"></label>
            <label>Ends (DC time)<input type="datetime-local" wire:model="form.ends_at" class="{{ $input }}"></label>
            <label>Badge name<input wire:model="form.badge_name" maxlength="60" placeholder="Very Best Eater" class="{{ $input }}"></label>
            <label>Prize (optional)<input wire:model="form.prize" maxlength="500" placeholder="A Washingtonian tote and a year’s subscription" class="{{ $input }}"></label>
        </div>
        @foreach (['form.list_name', 'form.title', 'form.goal', 'form.starts_at', 'form.ends_at', 'form.prize', 'form.badge_name'] as $f) @error($f) <p class="text-flag">{{ $message }}</p> @enderror @endforeach
        <div><button class="mt-3 rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Create the challenge</button></div>
    </form>

    <section class="mt-3 rounded-xl border border-rule bg-card p-4 text-sm">
        <h2 class="font-semibold">Challenges</h2>
        <ul class="mt-2 divide-y divide-rule">
            @forelse ($challenges as $c)
                <li wire:key="challenge-{{ $c->id }}" class="py-2">
                    <a href="{{ route('challenges.show', $c) }}" class="font-semibold underline">{{ $c->title }}</a>
                    <span class="text-mute">· {{ $c->goal }} of {{ $c->list_name }} · {{ $c->starts_at->timezone($tz)->format('M j') }}–{{ $c->ends_at->timezone($tz)->format('M j') }} · badge “{{ $c->badge_name }}”</span>
                    <div class="mt-1 flex flex-wrap gap-3">
                        <button wire:click="show({{ $c->id }})" class="underline">{{ $counts[$c->id] ?? 0 }} {{ str('finisher')->plural($counts[$c->id] ?? 0) }}</button>
                        <a href="{{ route('admin.challenges.csv', $c) }}" class="underline">CSV</a>
                    </div>
                    @if ($c->prize)<p class="text-mute">Prize: {{ $c->prize }}</p>@endif
                    @if ($showing === $c->id)
                        <table class="mt-2 w-full text-left">
                            <thead class="text-mute"><tr><th class="py-1">Who</th><th>Email</th><th>Finished</th></tr></thead>
                            <tbody>
                                @forelse ($finishers as $f)
                                    <tr><td class="py-1">{{ '@'.$f->username }} · {{ $f->name }}</td><td>{{ $f->email }}</td><td>{{ \Illuminate\Support\Carbon::parse($f->finished_at)->timezone($tz)->format('M j, g:ia') }}</td></tr>
                                @empty
                                    <tr><td colspan="3" class="py-1 text-mute">Nobody has finished yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    @endif
                </li>
            @empty
                <li class="py-2 text-mute">No challenges yet.</li>
            @endforelse
        </ul>
    </section>
</div>
