@php($archive = \App\Support\Archive::forStop($stop))
@if ($archive->isNotEmpty())
    <section class="mt-4 text-sm" aria-labelledby="archive-{{ $stop }}">
        <h2 id="archive-{{ $stop }}" class="text-[10.5px] font-semibold uppercase tracking-[0.13em] text-mute">From the archive</h2>
        <ul class="mt-1.5 space-y-1">
            @foreach ($archive as $story)
                <li><a href="{{ $story->url }}" rel="noopener" class="font-semibold hover:underline">{{ $story->title }}</a>@if ($story->published_on) <span class="text-mute">· {{ \Illuminate\Support\Carbon::parse($story->published_on)->format('Y') }}</span>@endif</li>
            @endforeach
        </ul>
    </section>
@endif
