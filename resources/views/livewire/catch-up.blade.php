<div class="mt-3">
    @if (! $digest)
        <button type="button" wire:click="catchUp" class="inline-flex items-center gap-1.5 rounded-full border border-rule px-3 py-1 text-[13px] font-semibold hover:border-ink">
            <flux:icon.sparkles variant="micro" />Catch me up
            <span wire:loading wire:target="catchUp" class="font-normal text-mute">…</span>
        </button>
    @else
        <section class="rounded-xl border border-rule bg-card p-3.5 text-sm" aria-label="The last 24 hours">
            <div class="flex items-center justify-between">
                <h2 class="text-[11px] font-semibold uppercase tracking-[0.13em] text-mute">The last 24 hours</h2>
                <button type="button" wire:click="$set('open', false)" class="text-mute" aria-label="Close">×</button>
            </div>
            @if ($digest['bullets'])
                <ul class="mt-1.5 list-disc space-y-1 pl-5">
                    @foreach ($digest['bullets'] as $bullet)
                        <li>{{ $bullet }}</li>
                    @endforeach
                </ul>
                <p class="mt-1.5 text-[12px] text-mute">Summarized by Claude from approved posts. It can get things wrong.</p>
            @elseif ($digest['posts']->isEmpty())
                <p class="mt-1.5 text-mute">Quiet here in the last day.</p>
            @else
                <ol class="mt-1.5 space-y-1">
                    @foreach ($digest['posts'] as $post)
                        <li><a href="{{ route('dispatches.show', $post) }}" wire:navigate class="hover:underline">{{ str($post->body)->limit(120) }}</a> <span class="text-mute">· {{ $post->replies_count }} {{ str('reply')->plural($post->replies_count) }}</span></li>
                    @endforeach
                </ol>
                @unless ($aiOn)
                    <p class="mt-1.5 text-[12px] text-mute">The top posts. Summaries need ANTHROPIC_API_KEY.</p>
                @endunless
            @endif
        </section>
    @endif
</div>
