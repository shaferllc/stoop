<div class="space-y-3">
    @php($input = 'mt-0.5 block w-full rounded-md border border-rule bg-paper px-2 py-1')
    <section class="rounded-xl border border-rule bg-card p-5">
        <h1 class="font-display text-3xl font-bold">Promote this {{ $promoKind }}</h1>
        <blockquote class="mt-2 border-l-2 border-rule pl-3 text-sm">
            @if ($target instanceof \App\Models\PlaceDeal)
                <b>{{ $target->title }}</b> · {{ $target->place->name }}
            @else
                {{ str($target->body)->limit(200) }}
            @endif
        </blockquote>
        <p class="mt-2 text-sm text-mute">It shows in feeds as “Promoted · name”, at most once per {{ \App\Support\Ads::EVERY }} posts and 3 times a day per person, only on the stops and topics you pick (none means everywhere). Our team reviews every promotion before it runs and refunds it if it isn’t approved.</p>

        <form wire:submit="pay" class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
            <label>Stops (up to 10)
                <select multiple wire:model="form.stops" size="6" class="{{ $input }}">@foreach (config('stoop.stations') as $code => $n)<option value="{{ $code }}">{{ $code }} · {{ $n }}</option>@endforeach</select>
                @error('form.stops')<span class="text-flag">{{ $message }}</span>@enderror @error('form.stops.*')<span class="text-flag">{{ $message }}</span>@enderror
            </label>
            <fieldset><legend>Topics</legend>
                @foreach (config('stoop.lines') as $key => $l)
                    <label class="mr-3 inline-flex items-center gap-1"><input type="checkbox" wire:model="form.lines" value="{{ $key }}"> {{ $l['name'] }}</label>
                @endforeach
            </fieldset>
            <label>Starts<input type="date" wire:model="form.starts_on" class="{{ $input }}">@error('form.starts_on')<span class="text-flag">{{ $message }}</span>@enderror</label>
            <label>Ends<input type="date" wire:model="form.ends_on" class="{{ $input }}">@error('form.ends_on')<span class="text-flag">{{ $message }}</span>@enderror</label>
            @if ($free)
                <p class="rounded-lg bg-chip p-3 sm:col-span-2">This one’s free: your Business plan includes a promoted happening each month ({{ number_format(config('business.promotion_tiers.'.config('business.free_promotion_tier').'.impressions')) }} impressions).</p>
            @else
                <fieldset class="sm:col-span-2"><legend>Budget</legend>
                    @foreach (config('business.promotion_tiers') as $key => $tier)
                        <label class="mr-4 inline-flex items-center gap-1"><input type="radio" wire:model="form.tier" value="{{ $key }}"> ${{ $tier['cents'] / 100 }} for {{ number_format($tier['impressions']) }} impressions</label>
                    @endforeach
                    @error('form.tier')<span class="text-flag">{{ $message }}</span>@enderror
                </fieldset>
            @endif
            <div class="sm:col-span-2"><button class="rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">{{ $free ? 'Send for review' : 'Pay with Stripe' }}</button></div>
        </form>
    </section>
</div>
