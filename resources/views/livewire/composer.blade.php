<form wire:submit="file" id="{{ $parentId ? 'reply' : 'compose' }}" class="rounded-xl border border-rule bg-card p-3.5">
    @guest
        <p class="text-sm text-mute"><a href="{{ route('login') }}" class="font-semibold text-ink underline">Log in</a> or <a href="{{ route('register') }}" class="font-semibold text-ink underline">join</a> to {{ $parentId ? 'reply' : 'file a dispatch' }}.</p>
    @else
        @unless ($parentId)
            <div class="mb-2 flex flex-wrap gap-1.5" role="group" aria-label="Post type">
                @foreach (config('stoop.types') as $key => $label)
                    @continue($key === 'callout' && ! auth()->user()->is_staff)
                    <button type="button" wire:click="$set('type', '{{ $key }}')" @class([
                        'rounded-full border px-2.5 py-0.5 text-[12.5px] font-medium',
                        'border-ink bg-card text-ink' => $type === $key,
                        'border-transparent bg-chip text-mute' => $type !== $key,
                    ])>{{ $label }}</button>
                @endforeach
            </div>
        @endunless

        <label for="body-{{ $parentId ?? 'new' }}" class="sr-only">{{ $parentId ? 'Reply' : 'New dispatch' }}</label>
        <textarea id="body-{{ $parentId ?? 'new' }}" wire:model.live.debounce.400ms="body" rows="2" maxlength="{{ config('stoop.max_length') }}"
            x-on:keydown.meta.enter.prevent="$wire.file()" x-on:keydown.ctrl.enter.prevent="$wire.file()"
            placeholder="{{ $parentId ? 'Reply as a neighbor' : match ($type) {
                'ask' => 'Ask your neighbors a question',
                'poll' => 'Ask the question for your poll',
                'happening' => 'What’s happening, and where?',
                'been' => 'Where did you go, and what should people order?',
                'callout' => 'What do you want readers to tell the newsroom?',
                default => 'What’s happening on your block?',
            } }}"
            class="w-full resize-none bg-transparent outline-none"></textarea>

        @if ($quoted)
            <div class="mt-2 flex justify-between gap-2 border-l-3 border-rule px-2.5 py-1 text-[13px] text-mute">
                <span>Quoting {{ '@'.$quoted->user->username }}: {{ str($quoted->body)->limit(90) }}</span>
                <button type="button" wire:click="$set('quoteId', null)" aria-label="Remove quote" class="text-lg leading-none">×</button>
            </div>
        @endif

        @if ($type === 'poll' && ! $parentId)
            <div class="mt-2 grid gap-1.5 sm:grid-cols-2">
                @foreach ($options as $i => $option)
                    <input wire:model="options.{{ $i }}" maxlength="50" placeholder="Option {{ $i + 1 }}{{ $i > 1 ? ' (optional)' : '' }}" aria-label="Poll option {{ $i + 1 }}" class="rounded-md border border-rule bg-paper px-2.5 py-1.5 text-sm">
                @endforeach
            </div>
        @endif

        @if ($type === 'happening' && ! $parentId)
            <input wire:model="happeningAt" maxlength="60" placeholder="When? For example: Tonight · 8pm" aria-label="When" class="mt-2 w-full rounded-md border border-rule bg-paper px-2.5 py-1.5 text-sm">
        @endif

        @if ($warned)
            <div class="mt-2 rounded-lg border border-flag/40 bg-flag/5 px-3 py-2 text-sm" role="alert">
                <b>Would you say this to a neighbor?</b> That reads as a personal attack. Edit it, or file it again to post as is.
            </div>
        @endif

        @foreach (['body', 'options', 'happeningAt', 'type', 'line', 'stop'] as $field)
            @error($field) <p class="mt-1 text-sm text-flag">{{ $message }}</p> @enderror
        @endforeach

        <div class="mt-2 flex flex-wrap items-center gap-2">
            @unless ($parentId)
                <select wire:model="line" aria-label="Line" @disabled($type === 'ask') class="rounded-md border border-rule bg-paper px-1.5 py-1 text-[13px]">
                    @foreach (config('stoop.lines') as $key => $l)
                        <option value="{{ $key }}">{{ $l['name'] }} line</option>
                    @endforeach
                </select>
                <select wire:model="stop" aria-label="Stop" class="rounded-md border border-rule bg-paper px-1.5 py-1 text-[13px]">
                    <option value="">No stop</option>
                    @foreach (config('stoop.stations') as $code => $station)
                        <option value="{{ $code }}">{{ $code }} · {{ $station }}</option>
                    @endforeach
                </select>
            @endunless
            @php($left = config('stoop.max_length') - mb_strlen($body))
            <span @class(['ml-auto text-[13px] tabular-nums', 'font-semibold text-flag' => $left < 20, 'text-mute' => $left >= 20])>{{ $left }}</span>
            <button type="submit" wire:loading.attr="disabled" @disabled(mb_strlen(trim($body)) < 3) class="rounded-lg bg-flag px-3.5 py-1.5 text-[13px] font-semibold text-white disabled:opacity-40">
                {{ $parentId ? 'Reply' : ($type === 'ask' ? 'Ask' : 'File dispatch') }}
            </button>
        </div>
    @endguest
</form>
