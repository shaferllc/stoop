<div>
    <h1 class="font-display text-3xl font-bold">Operations</h1>
    <dl class="mt-4 grid gap-x-8 rounded-xl border border-rule bg-card p-4 sm:grid-cols-2">
        @foreach ($stats as $label => $value)
            <div class="flex justify-between border-b border-rule py-2"><dt class="text-mute">{{ $label }}</dt><dd class="font-semibold tabular-nums">{{ number_format($value) }}</dd></div>
        @endforeach
    </dl>

    <h2 class="mt-6 font-display text-xl font-bold">Failed jobs</h2>
    <ul class="mt-2 divide-y divide-rule rounded-xl border border-rule bg-card text-sm">
        @forelse ($failed as $job)
            <li wire:key="job-{{ $job->uuid }}" class="px-4 py-3">
                <div class="flex flex-wrap items-center gap-2">
                    <b>{{ json_decode($job->payload)->displayName ?? 'Job' }}</b>
                    <span class="text-mute">{{ \Illuminate\Support\Carbon::parse($job->failed_at)->diffForHumans() }}</span>
                    <button type="button" wire:click="retry('{{ $job->uuid }}')" class="ml-auto rounded-lg border border-rule px-2.5 py-0.5">Retry</button>
                    <button type="button" wire:click="forget('{{ $job->uuid }}')" class="rounded-lg border border-rule px-2.5 py-0.5">Dismiss</button>
                </div>
                <p class="mt-1 truncate font-mono text-[12px] text-mute">{{ str($job->exception)->before("\n") }}</p>
            </li>
        @empty
            <li class="p-6 text-center text-mute">No failed jobs.</li>
        @endforelse
    </ul>
</div>
