{{-- @sentinel's latest hourly summaries, for moderators on the admin Overview. --}}
<h2 class="mt-6 font-display text-xl font-bold">Sentinel</h2>
<p class="text-sm text-mute">Hourly moderation summaries, also sent to moderators. Hours with nothing to report are skipped.@unless (\App\Support\ClaudeText::enabled()) Plain summaries for now. Needs ANTHROPIC_API_KEY for Claude.@endunless</p>
<ul class="mt-2 divide-y divide-rule rounded-xl border border-rule bg-card text-sm">
    @forelse ($summaries as $summary)
        <li wire:key="sentinel-{{ $summary['hour'] }}" class="px-4 py-3">
            <span class="text-mute">{{ \Illuminate\Support\Carbon::parse($summary['hour'])->timezone(config('stoop.timezone'))->format('M j, g:ia') }}</span>
            <p>{{ $summary['text'] }}</p>
        </li>
    @empty
        <li class="p-6 text-center text-mute">Nothing to report yet.</li>
    @endforelse
</ul>
