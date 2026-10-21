<div>
    <h1 class="mb-2 font-display text-3xl font-bold">ANC summaries</h1>
    @include('partials.admin-nav')
    @php($input = 'rounded-md border border-rule bg-paper px-2.5 py-1.5')

    <section class="rounded-xl border border-rule bg-card p-4 text-sm">
        <p class="text-mute">Paste an ANC’s meeting notes or agenda. Claude drafts a short summary; edit it, check the preview, then publish. It posts from @stoop at every station in the ward.</p>
        @unless ($aiOn)<p class="mt-1 font-semibold text-flag">Needs ANTHROPIC_API_KEY to draft. You can still write and publish a summary yourself.</p>@endunless

        <div class="mt-3 grid gap-2">
            <label class="w-40">ANC
                <select wire:model.live="anc" class="mt-0.5 block w-full {{ $input }}">
                    @foreach (\App\Support\Neighborhoods::ancs() as $a)<option value="{{ $a }}">ANC {{ $a }}</option>@endforeach
                </select>
            </label>
            <textarea wire:model="notes" rows="8" maxlength="20000" placeholder="Paste the meeting notes or agenda" aria-label="Meeting notes" class="{{ $input }}"></textarea>
            <div><button type="button" wire:click="draft" @disabled(! $aiOn) class="rounded-lg border border-rule px-3 py-1.5 font-semibold disabled:opacity-40">Draft summary<span wire:loading wire:target="draft">…</span></button></div>
            <textarea wire:model.live.debounce.400ms="summary" rows="4" maxlength="{{ config('stoop.max_length') - 80 }}" placeholder="The summary neighbors will see" aria-label="Summary" class="{{ $input }}"></textarea>
            <p class="text-[12px] text-mute">{{ mb_strlen($summary) }} / {{ config('stoop.max_length') - 80 }}</p>
        </div>
        @foreach (['anc', 'notes', 'summary', 'body'] as $f) @error($f) <p class="mt-1 text-flag">{{ $message }}</p> @enderror @endforeach

        @if ($preview)
            <div class="mt-3 rounded-lg border border-dashed border-rule p-3">
                <div class="text-[11px] font-semibold uppercase tracking-wide text-mute">Preview · @stoop · Culture line · {{ count($stations) }} {{ str('station')->plural(count($stations)) }}: {{ collect($stations)->map(fn ($s) => config("stoop.stations.$s"))->join(', ') }}</div>
                <p class="mt-1 whitespace-pre-line">{{ $preview }}</p>
            </div>
            <button type="button" wire:click="publish" wire:confirm="Post this from @stoop at {{ count($stations) }} stations?" class="mt-2 rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Publish</button>
        @endif
    </section>

    <ul class="mt-3 divide-y divide-rule rounded-xl border border-rule bg-card text-sm">
        @foreach ($recent as $r)
            <li class="px-4 py-2"><b>ANC {{ $r->anc }}</b> <span class="text-mute">· {{ \Illuminate\Support\Carbon::parse($r->published_at)->diffForHumans() }}</span><p>{{ $r->summary }}</p></li>
        @endforeach
    </ul>
</div>
