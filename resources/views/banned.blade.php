<x-layouts::stoop title="Account banned">
    <article class="rounded-xl border border-rule bg-card p-6">
        <h1 class="font-display text-3xl font-bold">This account is banned</h1>
        @if ($ban)
            <p class="mt-2">Reason: {{ $ban->reason }}</p>
            <p class="mt-1 text-mute">Banned on {{ $ban->created_at->toFormattedDateString() }}.</p>
            <a href="{{ route('appeals.create', ['penalty' => $ban->id]) }}" class="mt-4 inline-block rounded-lg bg-flag px-4 py-2 text-sm font-semibold text-white">Appeal</a>
        @endif
        <p class="mt-4 text-sm text-mute">You can still <a href="{{ route('profile.edit') }}" class="underline">delete your account</a> or sign out. Deleting the account doesn’t lift the ban.</p>
        <form method="POST" action="{{ route('logout') }}" class="mt-2">@csrf<button class="text-sm underline">Sign out</button></form>
    </article>
</x-layouts::stoop>
