{{-- A read-only peek at a thread's latest replies, loaded into a feed card. --}}
<ol class="space-y-2 border-l-2 pl-3" style="border-color: {{ $dispatch->lineColor() }}" aria-label="Latest replies">
    @foreach ($replies as $reply)
        <li class="text-[14px]">
            <a href="{{ route('users.show', $reply->user) }}" wire:navigate class="font-semibold">{{ $reply->user->name }}</a>
            <span class="text-mute">{{ '@'.$reply->user->username }} · {{ $reply->created_at->shortAbsoluteDiffForHumans() }}</span>
            <p class="whitespace-pre-line break-words">{!! \App\Support\Mentions::render($reply->hidden_by_author ? 'Hidden by the author.' : $reply->body) !!}</p>
            @if ($reply->replies_count)
                <a href="{{ route('dispatches.show', $reply) }}" wire:navigate class="text-[12.5px] font-semibold text-mute hover:text-ink">{{ $reply->replies_count }} {{ str('reply')->plural($reply->replies_count) }} →</a>
            @endif
        </li>
    @endforeach
</ol>
<a href="{{ route('dispatches.show', $dispatch) }}" wire:navigate class="mt-2 inline-block text-[13px] font-semibold hover:underline">{{ $total > $replies->count() ? 'See all '.$total.' replies' : 'Open the thread' }} →</a>
