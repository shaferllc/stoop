<div class="space-y-3">
    @php($select = 'rounded-md border border-rule bg-paper px-1.5 py-1 text-[13px]')
    @php($input = 'mt-0.5 block w-full rounded-md border border-rule bg-card px-2 py-1')
    <div>
        <h1 class="font-display text-3xl font-bold">Classifieds</h1>
        <p class="text-mute">Local jobs, apartments and rooms, roommates and things for sale. Listings run {{ config('classifieds.days') }} days. To keep everyone safe there are no phone numbers or emails: contact people through Stoop messages.</p>
    </div>

    <div class="flex flex-wrap items-center gap-2 text-sm">
        <select wire:model.live="category" aria-label="Category" class="{{ $select }}">
            <option value="">Everything</option>
            @foreach (config('classifieds.categories') as $key => $c)<option value="{{ $key }}">{{ $c['name'] }}</option>@endforeach
        </select>
        <select wire:model.live="line" aria-label="Line" class="{{ $select }}">
            <option value="">All lines</option>
            @foreach (config('stoop.lines') as $key => $l)<option value="{{ $key }}">{{ ucfirst($key) }} line</option>@endforeach
        </select>
        <select wire:model.live="stop" aria-label="Stop" class="{{ $select }}">
            <option value="">All stops</option>
            @foreach (collect(config('stoop.stations'))->sort() as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@endforeach
        </select>
        <input type="number" min="0" wire:model.live.debounce.400ms="min" placeholder="Min $" aria-label="Lowest price" class="{{ $select }} w-24">
        <input type="number" min="0" wire:model.live.debounce.400ms="max" placeholder="Max $" aria-label="Highest price" class="{{ $select }} w-24">
        @unless ($posting)
            <button type="button" wire:click="startPosting" class="ml-auto rounded-lg bg-flag px-3 py-1 font-semibold text-white">Post a listing</button>
        @endunless
    </div>

    @if ($posting)
        <form wire:submit="post" class="space-y-2 rounded-xl border border-rule bg-card p-4 text-sm">
            <fieldset>
                <legend class="font-semibold">What are you listing?</legend>
                <div class="mt-1 grid gap-1.5 sm:grid-cols-2">
                    @foreach (config('classifieds.categories') as $key => $c)
                        @php($open = \App\Support\Classifieds::canPost($key))
                        <label @class(['flex items-center gap-2 rounded-lg border px-3 py-1.5', 'border-ink' => $form['category'] === $key, 'border-rule' => $form['category'] !== $key, 'opacity-60' => ! $open])>
                            <input type="radio" wire:model.live="form.category" value="{{ $key }}" @disabled(! $open)>
                            <span>{{ $c['name'] }}</span>
                            <span class="ml-auto text-[12px] text-mute">{{ $open ? \App\Support\Classifieds::priceLabel($key) : 'Coming soon' }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <label class="block">Title<input wire:model="form.title" maxlength="100" class="{{ $input }}"></label>
            <label class="block">Details<textarea wire:model="form.body" rows="4" maxlength="2000" class="{{ $input }}"></textarea></label>
            <p class="text-[12px] text-mute">No phone numbers or emails; people will message you on Stoop.@if (config('classifieds.categories.'.$form['category'].'.housing')) Housing ads follow <a href="{{ config('classifieds.fair_housing_url') }}" target="_blank" rel="noopener" class="underline">DC’s fair housing rules</a>: no turning away vouchers, kids, or anyone for their race, religion or origin.@endif</p>
            <div class="grid gap-2 sm:grid-cols-2">
                <label>Nearest stop<select wire:model="form.stop" class="{{ $input }}"><option value="">None</option>@foreach (collect(config('stoop.stations'))->sort() as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@endforeach</select></label>
                @if ($form['category'] === 'jobs')
                    <div class="grid grid-cols-3 gap-1.5">
                        <label>Pay from $<input type="number" min="1" wire:model="form.pay_min" class="{{ $input }}"></label>
                        <label>to $<input type="number" min="1" wire:model="form.pay_max" class="{{ $input }}"></label>
                        <label>Per<select wire:model="form.pay_period" class="{{ $input }}">@foreach (config('classifieds.pay_periods') as $key => $label)<option value="{{ $key }}">{{ $key }}</option>@endforeach</select></label>
                    </div>
                @else
                    <label>{{ in_array($form['category'], ['housing', 'roommates'], true) ? 'Rent a month' : 'Price' }} ($, empty if not priced)<input type="number" min="0" wire:model="form.price" class="{{ $input }}"></label>
                @endif
            </div>
            @if ($form['category'] === 'jobs')<p class="text-[12px] text-mute">DC law requires a pay range on job ads.</p>@endif
            @foreach (['category', 'title', 'body', 'stop', 'price', 'pay_min', 'pay_max', 'pay_period'] as $f) @error("form.$f") <p class="text-flag">{{ $message }}</p> @enderror @endforeach
            <div class="flex items-center gap-2">
                <button class="rounded-lg bg-flag px-3 py-1.5 font-semibold text-white" wire:loading.attr="disabled">{{ \App\Support\Classifieds::price($form['category']) ? 'Continue to payment' : 'Post listing' }}</button>
                <button type="button" wire:click="$set('posting', false)" class="rounded-lg border border-rule px-3 py-1.5">Cancel</button>
                @if (\App\Support\Classifieds::price($form['category']))<span class="text-[12px] text-mute">Secure checkout by Stripe (test mode).</span>@endif
            </div>
        </form>
    @endif

    <div class="space-y-2">
        @forelse ($listings as $listing)
            <a href="{{ route('classifieds.show', $listing) }}" wire:navigate wire:key="l-{{ $listing->id }}" class="block rounded-xl border border-rule bg-card p-4 hover:border-ink">
                <div class="flex flex-wrap items-baseline gap-x-2 text-[12px] text-mute">
                    <span class="font-semibold uppercase tracking-wide text-flag">{{ $listing->categoryName() }}</span>
                    @if ($listing->stop)<span>· {{ $listing->stopName() }}</span>@endif
                    <span>· {{ $listing->created_at->diffForHumans() }}</span>
                </div>
                <div class="flex items-baseline justify-between gap-3">
                    <h2 class="font-display text-lg font-bold">{{ $listing->title }}</h2>
                    @if ($listing->priceLabel())<b class="whitespace-nowrap tabular-nums">{{ $listing->priceLabel() }}</b>@endif
                </div>
                <p class="line-clamp-2 text-sm">{{ $listing->body }}</p>
            </a>
        @empty
            <x-empty title="Nothing listed here yet" art="platform">Have a job, a room or something to sell? Post a listing.</x-empty>
        @endforelse
    </div>

    @if ($listings->count() >= $limit)
        <button type="button" wire:click="loadMore" class="w-full rounded-lg border border-rule py-2 text-sm text-mute hover:text-ink">Load more</button>
    @endif
</div>
