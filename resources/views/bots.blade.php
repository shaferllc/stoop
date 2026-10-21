<x-layouts::stoop title="Bots">
    <article class="rounded-xl border border-rule bg-card p-6">
        <h1 class="font-display text-3xl font-bold">Stoop’s bots</h1>
        <p class="mt-2 max-w-prose text-mute">House accounts that answer when you mention them, or post on their own schedule. They’re labeled BOT, can’t moderate anyone, and you can mute any of them from their profile.</p>

        <ul class="mt-5 divide-y divide-rule">
            @foreach ([['stoop', 'Answers questions about how Stoop works. Try “@stoop how do half-smokes work?”'], ['dc', 'Say “hello DC” in a post and it says hello back.']] as [$name, $about])
                <li class="flex gap-3 py-3">
                    <a href="{{ url('@'.$name) }}" wire:navigate class="w-28 shrink-0 font-semibold">{{ '@'.$name }}</a>
                    <p class="text-sm">{{ $about }}</p>
                </li>
            @endforeach
            @foreach (collect($bots)->sortBy(fn ($bot) => $bot->username()) as $bot)
                <li class="flex gap-3 py-3">
                    <a href="{{ url('@'.$bot->username()) }}" wire:navigate class="w-28 shrink-0 font-semibold">{{ '@'.$bot->username() }}</a>
                    <p class="text-sm">{{ $bot->description() }}</p>
                </li>
            @endforeach
        </ul>

        <p class="mt-4 text-sm text-mute">Mention any bot in a dispatch or reply and it answers. If it only posts on a schedule, it tells you what it does.</p>
    </article>
</x-layouts::stoop>
