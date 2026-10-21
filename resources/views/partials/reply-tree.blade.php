{{-- One level of a conversation. Each reply shows its own replies underneath, collapsible, down to $maxDepth. --}}
@php($color = $dispatch->lineColor())
<ol @class(['relative space-y-2.5', 'pl-6' => $depth === 0, 'mt-2.5 pl-4 sm:pl-5' => $depth > 0]) aria-label="{{ $depth ? 'Replies to this reply' : 'Replies' }}">
    <span @class(['absolute bottom-4 top-4 rounded-full', 'left-[9px] w-1' => $depth === 0, 'left-[3px] w-0.5 opacity-50' => $depth > 0]) style="background: {{ $color }}" aria-hidden="true"></span>
    @foreach ($children[$parentId] as $reply)
        @php($kids = $children[$reply->id] ?? collect())
        @php($total = $counts[$reply->id] ?? 0)
        <li class="relative" wire:key="reply-{{ $reply->id }}" x-data="{ open: {{ $depth < 2 ? 'true' : 'false' }} }">
            @if ($depth === 0)
                <span class="absolute -left-[21px] top-5 size-3.5 rounded-full border-[3px] bg-paper" style="border-color: {{ $color }}" aria-hidden="true"></span>
            @endif
            @if ($reply->hidden_by_author)
                <div x-data="{ reveal: false }">
                    <button type="button" x-show="!reveal" x-on:click="reveal = true" class="w-full rounded-xl border border-dashed border-rule p-3 text-sm text-mute">A reply hidden by the author. Show it</button>
                    <div x-show="reveal" x-cloak><x-dispatch-card :dispatch="$reply" :translated="$translations[$reply->id] ?? null" :state="$state" in-thread /></div>
                </div>
            @else
                <x-dispatch-card :dispatch="$reply" :translated="$translations[$reply->id] ?? null" :state="$state" in-thread />
            @endif

            @if ($kids->isNotEmpty())
                @if ($depth + 1 >= $maxDepth)
                    <a href="{{ route('dispatches.show', $reply) }}" wire:navigate class="ml-4 mt-1.5 inline-block text-[13px] font-semibold text-mute hover:text-ink">Continue this thread ({{ $total }}) →</a>
                @else
                    <button type="button" x-on:click="open = !open" :aria-expanded="open" class="ml-4 mt-1.5 inline-flex items-center gap-1 text-[13px] font-semibold text-mute hover:text-ink">
                        <flux:icon.chevron-down variant="micro" x-bind:class="open ? '' : '-rotate-90'" class="transition-transform" />
                        <span x-text="open ? 'Hide {{ $total }} {{ str('reply')->plural($total) }}' : 'Show {{ $total }} {{ str('reply')->plural($total) }}'"></span>
                    </button>
                    <div x-show="open" x-cloak>
                        @include('partials.reply-tree', ['parentId' => $reply->id, 'depth' => $depth + 1])
                    </div>
                @endif
            @endif
        </li>
    @endforeach
</ol>
