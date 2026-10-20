<form wire:submit="file" id="{{ $parentId ? 'reply' : 'compose' }}" class="rounded-xl border border-rule bg-card p-3.5">
    @guest
        <p class="text-sm text-mute"><a href="{{ route('login') }}" class="font-semibold text-ink underline">Log in</a> or <a href="{{ route('register') }}" class="font-semibold text-ink underline">join</a> to {{ $parentId ? 'reply' : 'file a dispatch' }}.</p>
    @else
        @if (! auth()->user()->accepted_rules_at)
            <div class="rounded-lg border border-rule bg-paper p-3 text-sm">
                <p class="font-display text-lg font-bold">Before your first post</p>
                <ul class="mt-1.5 list-disc space-y-1 pl-5 text-mute">
                    <li><b class="text-ink">No attacks on people for who they are.</b> Argue about places, not neighbors.</li>
                    <li><b class="text-ink">Describe what happened, not who.</b> Crime and safety posts never describe someone by race alone.</li>
                    <li><b class="text-ink">No campaign posts or networking pitches.</b></li>
                </ul>
                <div class="mt-2.5 flex items-center gap-3">
                    <button type="button" wire:click="acceptRules" class="rounded-lg bg-flag px-3 py-1.5 text-[13px] font-semibold text-white">Got it</button>
                    <a href="{{ route('rules') }}" wire:navigate class="text-mute underline">Read the full rules</a>
                </div>
            </div>
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

            <div class="relative" x-data="mentionPicker($wire)">
                <label for="body-{{ $parentId ?? 'new' }}" class="sr-only">{{ $parentId ? 'Reply' : 'New dispatch' }}</label>
                <textarea id="body-{{ $parentId ?? 'new' }}" x-ref="input" wire:model.live.debounce.400ms="body" rows="2" maxlength="{{ config('stoop.max_length') }}"
                    x-on:input="lookup()" x-on:keydown="keys($event)" x-on:blur="setTimeout(() => open = false, 150)"
                    x-on:keydown.meta.enter.prevent="$wire.file()" x-on:keydown.ctrl.enter.prevent="$wire.file()"
                    data-composer
                    placeholder="{{ $parentId ? 'Reply as a neighbor' : match ($type) {
                        'ask' => 'Ask your neighbors a question',
                        'poll' => 'Ask the question for your poll',
                        'happening' => 'What’s happening, and where?',
                        'been' => 'Where did you go, and what should people order?',
                        'callout' => 'What do you want readers to tell the newsroom?',
                        default => 'What’s happening on your block?',
                    } }}"
                    class="w-full resize-none bg-transparent outline-none"></textarea>
                <ul x-show="open && items.length" x-cloak role="listbox" class="absolute left-0 z-20 mt-1 w-64 overflow-hidden rounded-lg border border-rule bg-card text-sm shadow-lg">
                    <template x-for="(item, i) in items" :key="item.username">
                        <li role="option" :aria-selected="i === active" x-on:mousedown.prevent="pick(item)" :class="i === active ? 'bg-chip' : ''" class="cursor-pointer px-3 py-1.5">
                            <b x-text="'@' + item.username"></b> <span class="text-mute" x-text="item.name"></span>
                        </li>
                    </template>
                </ul>
            </div>

            @if ($quoted)
                <div class="mt-2 flex justify-between gap-2 border-l-3 border-rule px-2.5 py-1 text-[13px] text-mute">
                    <span>Quoting {{ '@'.$quoted->user->username }}: {{ str($quoted->body)->limit(90) }}</span>
                    <button type="button" wire:click="$set('quoteId', null)" aria-label="Remove quote" class="text-lg leading-none">×</button>
                </div>
            @endif

            @if ($photos)
                <div class="mt-2 grid gap-2 sm:grid-cols-3">
                    @foreach ($photos as $i => $photo)
                        <div wire:key="photo-{{ $i }}" class="rounded-lg border border-rule p-1.5">
                            @if (method_exists($photo, 'isPreviewable') && $photo->isPreviewable())
                                <img src="{{ $photo->temporaryUrl() }}" alt="" class="aspect-video w-full rounded object-cover">
                            @endif
                            <input wire:model="alts.{{ $i }}" maxlength="1000" placeholder="Describe the photo" aria-label="Photo {{ $i + 1 }} description" class="mt-1.5 w-full rounded border border-rule bg-paper px-2 py-1 text-xs">
                            <button type="button" wire:click="removePhoto({{ $i }})" class="mt-1 text-xs text-mute underline">Remove</button>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($type === 'poll' && ! $parentId)
                <div class="mt-2 grid gap-1.5 sm:grid-cols-2">
                    @foreach ($options as $i => $option)
                        <input wire:model="options.{{ $i }}" maxlength="50" placeholder="Option {{ $i + 1 }}{{ $i > 1 ? ' (optional)' : '' }}" aria-label="Poll option {{ $i + 1 }}" class="rounded-md border border-rule bg-paper px-2.5 py-1.5 text-sm">
                    @endforeach
                </div>
                <label class="mt-2 flex items-center gap-2 text-[13px] text-mute">Poll runs for
                    <select wire:model="pollHours" class="rounded-md border border-rule bg-paper px-1.5 py-1 text-ink">
                        <option value="1">1 hour</option><option value="24">1 day</option><option value="72">3 days</option><option value="168">7 days</option>
                    </select>
                </label>
            @endif

            @if ($type === 'happening' && ! $parentId)
                <input wire:model="happeningAt" maxlength="60" placeholder="When? For example: Tonight · 8pm" aria-label="When" class="mt-2 w-full rounded-md border border-rule bg-paper px-2.5 py-1.5 text-sm">
            @endif

            @if ($warned)
                <div class="mt-2 rounded-lg border border-flag/40 bg-flag/5 px-3 py-2 text-sm" role="alert">
                    <b>Would you say this to a neighbor?</b> That reads as a personal attack. Edit it, or file it again to post as is.
                </div>
            @endif

            @foreach (['body', 'options', 'happeningAt', 'type', 'line', 'stop', 'publishAt', 'photos', 'photos.*', 'alts.*'] as $field)
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
                <label class="cursor-pointer rounded-md border border-rule px-2 py-1 text-[13px] text-mute hover:text-ink" title="Add up to 3 photos">
                    Photo<input type="file" wire:model="photos" multiple accept="image/jpeg,image/png,image/webp" class="sr-only">
                </label>
                @unless ($parentId)
                    <details class="text-[13px] text-mute" @if ($publishAt) open @endif>
                        <summary class="cursor-pointer rounded-md border border-rule px-2 py-1 hover:text-ink">Schedule</summary>
                        <input type="datetime-local" wire:model="publishAt" aria-label="Publish at" class="mt-1 rounded-md border border-rule bg-paper px-1.5 py-1 text-ink">
                    </details>
                @endunless
                @php($left = config('stoop.max_length') - mb_strlen($body))
                <span @class(['ml-auto text-[13px] tabular-nums', 'font-semibold text-flag' => $left < 20, 'text-mute' => $left >= 20])>{{ $left }}</span>
                <button type="submit" wire:loading.attr="disabled" @disabled(mb_strlen(trim($body)) < 3 && ! $photos) class="rounded-lg bg-flag px-3.5 py-1.5 text-[13px] font-semibold text-white disabled:opacity-40">
                    {{ $parentId ? 'Reply' : ($publishAt ? 'Schedule' : ($type === 'ask' ? 'Ask' : 'File dispatch')) }}
                </button>
            </div>
        @endif
    @endguest
</form>
