<div>
    <div class="mb-4">@include('partials.games-nav')</div>
    <div class="flex flex-wrap items-baseline gap-x-3">
        <h1 class="font-display text-3xl font-bold">The Daily</h1>
        <span class="font-mono text-sm text-mute">No. {{ $number }} · {{ now(config('stoop.timezone'))->format('l, F j') }}</span>
    </div>
    <p class="text-mute">Five questions about DC. Same five for everyone, new ones at midnight.</p>
    <x-presented-by target="daily" />

    @guest
        <x-empty title="Log in to play" art="stoop" class="mt-4"><a href="{{ route('login') }}" class="underline">Log in</a> or <a href="{{ route('register') }}" class="underline">join</a> to play and keep a streak.</x-empty>
    @else
        @php($answered = count($attempt->answers))
        <div class="mt-4 flex gap-1.5" aria-label="Progress">
            @foreach ($questions as $i => $q)
                @php($a = $attempt->answers[$i] ?? null)
                <span @class(['h-2 flex-1 rounded-full', 'bg-chip' => ! $a, 'bg-[#bf0d3e]' => $a && $a['correct'] && $i % 5 === 0, 'bg-[#ed8b00]' => $a && $a['correct'] && $i % 5 === 1, 'bg-[#009cde]' => $a && $a['correct'] && $i % 5 === 2, 'bg-[#00b140]' => $a && $a['correct'] && $i % 5 === 3, 'bg-[#e0b400]' => $a && $a['correct'] && $i % 5 === 4, 'bg-mute/40' => $a && ! $a['correct']])></span>
            @endforeach
        </div>

        @if ($share)
            <section class="mt-4 rounded-xl border border-rule bg-card p-5 text-center">
                <p class="font-display text-4xl font-bold">{{ $attempt->score }}/{{ count($attempt->question_ids) }}</p>
                <p class="mt-1 text-mute">{{ ['Tourist energy. Try again tomorrow.', 'You’ve been here a minute.', 'Solid. A real Washingtonian.', 'Impressive. Do you work for the city?', 'Nearly perfect. Respect.', 'Perfect. You might be the mayor.'][$attempt->score] ?? '' }}</p>
                <pre class="mx-auto mt-3 w-fit rounded-lg bg-chip px-4 py-2 text-left text-sm" id="quiz-share">{{ $share }}</pre>
                <button type="button" x-data="{ copied: false }" x-on:click="navigator.clipboard.writeText(document.getElementById('quiz-share').textContent).then(() => copied = true)" class="mt-3 rounded-lg bg-flag px-4 py-2 text-sm font-semibold text-white">
                    <span x-text="copied ? 'Copied' : 'Copy result'">Copy result</span>
                </button>
                <p class="mt-3 text-sm">Quiz streak: <b>{{ $streaks['current'] }}</b> {{ str('day')->plural($streaks['current']) }} · Longest {{ $streaks['longest'] }}
                    @if ($streaks['pass_used_this_week']) · <span class="text-mute" title="One missed day a week doesn’t break your streak.">Single-tracking pass used this week</span> @endif</p>
                <p class="mt-1 text-[13px] text-mute">{{ $players }} {{ str('neighbor')->plural($players) }} played today · average {{ $average }}/5</p>
            </section>

            <ol class="mt-4 space-y-2 text-sm">
                @foreach ($questions as $i => $q)
                    <li class="rounded-xl border border-rule bg-card p-3">
                        <p class="font-semibold">{{ $q->question }}</p>
                        <p class="mt-1">{{ $attempt->answers[$i]['correct'] ? '✓' : '✗' }} {{ $q->choices[$q->answer] }} <span class="text-mute">· {{ $q->explanation }}</span></p>
                    </li>
                @endforeach
            </ol>
        @elseif ($q = $questions[$answered] ?? null)
            @php($last = $answered > 0 ? $questions[$answered - 1] : null)
            @if ($last)
                @php($lastAnswer = $attempt->answers[$answered - 1])
                <p @class(['mt-4 rounded-lg px-3 py-2 text-sm', 'bg-[#00b140]/10' => $lastAnswer['correct'], 'bg-chip' => ! $lastAnswer['correct']])>
                    <b>{{ $lastAnswer['correct'] ? 'Right.' : 'Not quite: '.$last->choices[$last->answer].'.' }}</b> {{ $last->explanation }}
                </p>
            @endif
            <section class="mt-4 rounded-xl border border-rule bg-card p-5" wire:key="q-{{ $q->id }}">
                <p class="text-[12px] font-semibold uppercase tracking-wide text-mute">Question {{ $answered + 1 }} of {{ count($questions) }}</p>
                <h2 class="mt-1 font-display text-2xl font-bold leading-snug">{{ $q->question }}</h2>
                <div class="mt-4 grid gap-2 sm:grid-cols-2">
                    @foreach ($q->choices as $c => $choice)
                        <button type="button" wire:click="choose({{ $c }})" wire:loading.attr="disabled" class="rounded-lg border border-rule px-4 py-3 text-left hover:border-ink">{{ $choice }}</button>
                    @endforeach
                </div>
            </section>
        @else
            <x-empty title="No quiz today" art="stoop" class="mt-4">The question bank is empty. Editors add questions in Admin → Quiz.</x-empty>
        @endif
    @endguest
</div>
