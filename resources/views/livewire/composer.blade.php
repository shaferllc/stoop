<form wire:submit="file" id="{{ $parentId ? 'reply' : 'compose-'.$this->getId() }}" class="rounded-xl border border-rule bg-card p-3.5">
    @guest
        <p class="text-sm text-mute"><a href="{{ route('login') }}" class="font-semibold text-ink underline">Log in</a> or <a href="{{ route('register') }}" class="font-semibold text-ink underline">join</a> to {{ $parentId ? 'reply' : 'file a dispatch' }}.</p>
    @else
        @if (! auth()->user()->accepted_rules_at)
            <div class="rounded-lg border border-rule bg-paper p-3 text-sm">
                <p class="font-display text-lg font-bold">Before your first post</p>
                <ul class="mt-1.5 list-disc space-y-1 pl-5 text-mute">
                    <li><b class="text-ink">Zero tolerance for hate speech.</b> A first offense is a 7-day timeout.</li>
                    <li><b class="text-ink">No swearing, anywhere.</b> Argue about places, not neighbors.</li>
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
                        @continue($key === 'snow' && ! $snowDay)
                        <button type="button" wire:click="$set('type', '{{ $key }}')" @class([
                            'rounded-full border px-2.5 py-0.5 text-[12.5px] font-medium',
                            'border-ink bg-card text-ink' => $type === $key,
                            'border-transparent bg-chip text-mute' => $type !== $key,
                        ])>{{ $label }}</button>
                    @endforeach
                </div>
            @endunless

            <div class="relative" x-data="mentionPicker($wire)">
                @if ($replyingTo)
                    <p class="mb-1.5 flex items-center gap-2 text-[13px] text-mute">
                        Replying to <b class="text-ink">{{ '@'.$replyingTo->user->username }}</b>
                        <button type="button" wire:click="replyToThread" class="rounded-md px-1.5 hover:bg-chip hover:text-ink" aria-label="Reply to the thread instead">×</button>
                    </p>
                @endif
                <label for="body-{{ $this->getId() }}" class="sr-only">{{ $parentId ? 'Reply' : 'New dispatch' }}</label>
                <textarea id="body-{{ $this->getId() }}" x-ref="input" wire:model.live.debounce.400ms="body" rows="2" maxlength="{{ config('stoop.max_length') }}"
                    x-on:input="lookup()" x-on:keydown="keys($event)" x-on:input.debounce.2500ms="{{ ! $parentId && \App\Support\Claude::enabled() ? '$wire.refineHints()' : '' }}" x-on:blur="setTimeout(() => open = false, 150)"
                    x-on:keydown.meta.enter.prevent="$wire.file()" x-on:keydown.ctrl.enter.prevent="$wire.file()"
                    data-composer
                    placeholder="{{ $parentId ? 'Reply as a neighbor' : match ($type) {
                        'ask' => 'Ask your neighbors a question',
                        'poll' => 'Ask the question for your poll',
                        'happening' => 'What’s happening, and where?',
                        'been' => 'Where did you go, and what should people order?',
                        'callout' => 'What do you want readers to tell the newsroom?',
                        'safety' => 'What happened?',
                        'snow' => 'How’s it looking out there?',
                        'openhouse' => 'What should neighbors know about the place?',
                        'lost' => 'Describe the pet or item, and where and when it was last seen',
                        default => 'What’s happening on your block?',
                    } }}"
                    class="w-full resize-none bg-transparent outline-none"></textarea>
                <ul x-show="open && items.length" x-cloak role="listbox" class="absolute left-0 z-20 mt-1 max-h-80 w-80 overflow-y-auto rounded-lg border border-rule bg-card text-sm shadow-lg">
                    <template x-for="(item, i) in items" :key="item.username">
                        <li role="option" :aria-selected="i === active" x-on:mousedown.prevent="pick(item)" :class="i === active ? 'bg-chip' : ''" class="cursor-pointer px-3 py-1.5">
                            <template x-if="item.bot && (i === 0 || !items[i - 1].bot)"><p class="-mx-3 -mt-1.5 mb-1 border-t border-rule bg-paper px-3 py-1 text-[11px] font-semibold uppercase tracking-wider text-mute">Bots</p></template>
                            <b x-text="'@' + item.username"></b> <span class="text-mute" x-text="item.name"></span>
                            <span x-show="item.bot" class="ml-1 rounded bg-chip px-1 text-[10px] font-semibold text-mute">BOT</span>
                            <p x-show="item.about" x-text="item.about" class="truncate text-[12px] text-mute"></p>
                        </li>
                    </template>
                </ul>
            </div>

            @unless ($parentId)
                {{-- Suggestions while typing: tap to use, never applied for you. --}}
                @php
                    $chips = array_filter([
                        'type' => ($hints['type'] ?? null) && $hints['type'] !== $type ? $hints['type'] : null,
                        'line' => ($hints['line'] ?? null) && $hints['line'] !== $line && ($hints['type'] ?? $type) !== 'ask' ? $hints['line'] : null,
                        'stop' => ($hints['stop'] ?? null) && $hints['stop'] !== $stop ? $hints['stop'] : null,
                    ]);
                @endphp
                @if ($chips || (\App\Support\Claude::enabled() && mb_strlen(trim($body)) >= 8))
                    <div class="mt-1.5 flex flex-wrap items-center gap-1.5 text-[12.5px]" aria-label="Suggestions">
                        @if ($chips)<span class="text-mute">Suggested:</span>@endif
                        @isset($chips['type'])
                            <button type="button" wire:click="$set('type', '{{ $chips['type'] }}')" class="rounded-full border border-dashed border-rule px-2.5 py-0.5 hover:border-ink">{{ config("stoop.types.{$chips['type']}") }}</button>
                        @endisset
                        @isset($chips['line'])
                            <button type="button" wire:click="$set('line', '{{ $chips['line'] }}')" class="inline-flex items-center gap-1 rounded-full border border-dashed border-rule px-2.5 py-0.5 hover:border-ink"><span class="size-2 rounded-full" style="background: {{ config("stoop.lines.{$chips['line']}.color") }}"></span>{{ config("stoop.lines.{$chips['line']}.name") }}</button>
                        @endisset
                        @isset($chips['stop'])
                            <button type="button" wire:click="$set('stop', '{{ $chips['stop'] }}')" class="rounded-full border border-dashed border-rule px-2.5 py-0.5 hover:border-ink">{{ config("stoop.stations.{$chips['stop']}") }} ({{ $chips['stop'] }})</button>
                        @endisset
                        @if (\App\Support\Claude::enabled())
                            <button type="button" wire:click="refineHints" class="ml-auto text-mute underline hover:text-ink">Suggest<span wire:loading wire:target="refineHints">…</span></button>
                        @elseif (auth()->user()->is_staff)
                            <span class="ml-auto text-mute">Keyword guesses. Claude suggestions need ANTHROPIC_API_KEY.</span>
                        @endif
                    </div>
                @endif
            @endunless

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

            @if (! $parentId && ($type === 'been' || $place || $showPlace))
                <div class="relative mt-2">
                    @if ($place)
                        <div class="flex flex-wrap items-center gap-2 rounded-lg border border-rule bg-paper px-2.5 py-1.5 text-sm">
                            <span>At <b>{{ $place->name }}</b>{{ $place->neighborhood ? ' · '.$place->neighborhood : '' }}</span>
                            @if ($place->owner_id === auth()->id())
                                <label class="flex items-center gap-1.5 text-[13px]"><input type="checkbox" wire:model="asBusiness"> Post as {{ $place->name }}</label>
                            @endif
                            <button type="button" wire:click="selectPlace(null)" class="ml-auto text-mute" aria-label="Remove place">×</button>
                        </div>
                    @else
                        <input wire:model.live.debounce.300ms="placeQuery" placeholder="{{ $type === 'been' ? 'Where did you go?' : 'Tag a place (optional)' }}" aria-label="Place" class="w-full rounded-md border border-rule bg-paper px-2.5 py-1.5 text-sm">
                        @if ($placeResults->isNotEmpty())
                            <ul class="absolute left-0 z-20 mt-1 w-full overflow-hidden rounded-lg border border-rule bg-card text-sm shadow-lg">
                                @foreach ($placeResults as $result)
                                    <li><button type="button" wire:click="selectPlace({{ $result->id }})" class="block w-full px-3 py-1.5 text-left hover:bg-chip">
                                        <b>{{ $result->name }}</b> <span class="text-mute">{{ collect([$result->category, $result->neighborhood, $result->listLabel()])->filter()->join(' · ') }}</span>
                                    </button></li>
                                @endforeach
                            </ul>
                        @elseif (mb_strlen(trim($placeQuery)) >= 2)
                            <p class="mt-1 text-[12.5px] text-mute">No place by that name yet. Staff add places from the magazine’s lists.</p>
                        @endif
                    @endif
                </div>
            @endif

            @if ($type === 'snow' && ! $parentId)
                <div class="mt-2 grid gap-2 rounded-lg border border-rule bg-paper p-2.5 text-sm sm:grid-cols-2">
                    <label>Snow so far (inches)<input type="number" step="0.5" min="0" max="60" wire:model="snowInches" class="mt-0.5 block w-full rounded-md border border-rule bg-card px-2 py-1"></label>
                    <label>Is your street plowed?<select wire:model="plowed" class="mt-0.5 block w-full rounded-md border border-rule bg-card px-2 py-1"><option value="">Pick one</option><option value="yes">Yes</option><option value="partly">Partly</option><option value="no">Not yet</option></select></label>
                    <p class="text-[12.5px] text-mute sm:col-span-2">Pick your stop below so the snow map knows where you are.</p>
                </div>
            @endif

            @if ($events->isNotEmpty() && ! $parentId)
                <div class="mt-2 flex flex-wrap items-center gap-1.5 text-[13px]">
                    <span class="text-mute">Happening now:</span>
                    @foreach ($events as $e)
                        <button type="button" wire:click="$set('eventId', {{ $eventId === $e->id ? 'null' : $e->id }})" @class(['rounded-full border px-2.5 py-0.5', 'border-ink' => $eventId === $e->id, 'border-transparent bg-chip text-mute' => $eventId !== $e->id])>{{ $e->name }}</button>
                    @endforeach
                    @php($chosen = $events->firstWhere('id', $eventId))
                    @if ($chosen?->rating_label)
                        <span class="ml-1 flex items-center gap-1">{{ $chosen->rating_label }}:
                            @foreach (range(1, 5) as $n)
                                <button type="button" wire:click="$set('rating', {{ $n }})" aria-label="{{ $n }} of 5" @class(['text-lg leading-none', 'text-flag' => $rating >= $n, 'text-rule' => ! $rating || $rating < $n])>★</button>
                            @endforeach
                        </span>
                    @endif
                </div>
            @endif

            @if ($type === 'callout' && auth()->user()->is_staff)
                <input wire:model="sponsor" maxlength="120" placeholder="Sponsored by (leave empty if not sponsored)" aria-label="Sponsor" class="mt-2 w-full rounded-md border border-rule bg-paper px-2.5 py-1.5 text-sm">
            @endif

            @if ($replyingToCallout)
                <label class="mt-2 flex items-center gap-2 text-[13px] text-mute"><input type="checkbox" wire:model="quoteOk"> The newsroom may quote this reply in a story.</label>
            @endif

            @if ($type === 'safety' && ! $parentId)
                <div class="mt-2 space-y-1.5 rounded-lg border border-rule bg-paper p-2.5 text-sm">
                    <p class="text-[13px] text-mute">Say what happened, where and when. If you describe a person, describe clothing, height, a vehicle or what they did. Race or gender alone isn’t allowed.</p>
                    <div class="grid gap-1.5 sm:grid-cols-2">
                        <input wire:model="safetyWhen" maxlength="60" placeholder="When? For example: Today, about 3pm" aria-label="When it happened" class="rounded-md border border-rule bg-card px-2.5 py-1.5">
                        <input wire:model="safetyBlock" maxlength="80" placeholder="Block or intersection (optional)" aria-label="Block or intersection" class="rounded-md border border-rule bg-card px-2.5 py-1.5">
                    </div>
                    <input wire:model="safetyPerson" maxlength="280" placeholder="Description of a person, if it helps (optional)" aria-label="Description of a person" class="w-full rounded-md border border-rule bg-card px-2.5 py-1.5">
                </div>
            @endif

            @includeWhen($type === 'openhouse' && ! $parentId, 'partials.composer-open-house')

            @if ($type === 'happening' && ! $parentId)
                <input wire:model="happeningAt" maxlength="60" placeholder="When? For example: Tonight · 8pm" aria-label="When" class="mt-2 w-full rounded-md border border-rule bg-paper px-2.5 py-1.5 text-sm">
            @endif

            @include('partials.composer-extras')

            @if ($warned)
                <div class="mt-2 rounded-lg border border-flag/40 bg-flag/5 px-3 py-2 text-sm" role="alert">
                    <b>Would you say this to a neighbor?</b> That reads as a personal attack. Edit it, or file it again to post as is.
                </div>
            @endif

            @foreach (['body', 'options', 'happeningAt', 'type', 'line', 'stop', 'publishAt', 'photos', 'photos.*', 'alts.*', 'safetyWhen', 'safetyPerson', 'placeId', 'sponsor', 'eventId', 'rating', 'snowInches', 'plowed'] as $field)
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
                        @foreach (collect(config('stoop.stations'))->sort() as $code => $station)
                            <option value="{{ $code }}">{{ $station }} ({{ $code }})</option>
                        @endforeach
                    </select>
                @endunless
                @unless ($parentId || $place || $type === 'been')
                    <button type="button" wire:click="$set('showPlace', true)" class="rounded-md border border-rule px-2 py-1 text-[13px] text-mute hover:text-ink">Place</button>
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
