<div>
    <h1 class="mb-2 font-display text-3xl font-bold">Quiz</h1>
    @include('partials.admin-nav')

    <form wire:submit="add" class="rounded-xl border border-rule bg-card p-4 text-sm">
        <h2 class="font-semibold">Add a question</h2>
        <input wire:model="question" maxlength="300" placeholder="Which Metro station has the longest escalator?" aria-label="Question" class="mt-2 w-full rounded-md border border-rule bg-paper px-2.5 py-1.5">
        <div class="mt-2 grid gap-2 sm:grid-cols-2">
            @foreach ($choices as $i => $c)
                <label class="flex items-center gap-2"><input type="radio" wire:model="answer" value="{{ $i }}" aria-label="Choice {{ $i + 1 }} is correct"><input wire:model="choices.{{ $i }}" maxlength="120" placeholder="Choice {{ $i + 1 }}" class="w-full rounded-md border border-rule bg-paper px-2.5 py-1.5"></label>
            @endforeach
        </div>
        <p class="mt-1 text-[12px] text-mute">Select the circle next to the right answer.</p>
        <input wire:model="explanation" maxlength="500" placeholder="One line on why, shown after answering" aria-label="Explanation" class="mt-2 w-full rounded-md border border-rule bg-paper px-2.5 py-1.5">
        @foreach (['question', 'choices.*', 'answer'] as $f) @error($f) <p class="text-flag">{{ $message }}</p> @enderror @endforeach
        <button class="mt-2 rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Add question</button>
    </form>

    <ul class="mt-3 divide-y divide-rule rounded-xl border border-rule bg-card text-sm">
        @foreach ($questions as $q)
            <li class="flex items-start gap-3 px-4 py-2.5">
                <div class="min-w-0 flex-1">
                    <p @class(['font-semibold', 'text-mute line-through' => ! $q->active])>{{ $q->question }} @if (in_array($q->id, $today))<span class="ml-1 rounded bg-flag px-1 text-[10px] font-bold text-white">TODAY</span>@endif</p>
                    <p class="text-mute">Answer: {{ $q->choices[$q->answer] }}</p>
                </div>
                <button type="button" wire:click="toggle({{ $q->id }})" class="rounded-lg border border-rule px-2.5 py-0.5">{{ $q->active ? 'Retire' : 'Restore' }}</button>
            </li>
        @endforeach
    </ul>
</div>
