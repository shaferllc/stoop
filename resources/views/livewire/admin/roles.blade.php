<div>
    <h1 class="mb-2 font-display text-3xl font-bold">Roles</h1>
    @include('partials.admin-nav')

    <form wire:submit="save" class="rounded-xl border border-rule bg-card p-4 text-sm">
        <h2 class="font-semibold">Set someone’s roles</h2>
        <input wire:model="username" placeholder="@username" aria-label="Username" class="mt-2 w-full rounded-md border border-rule bg-paper px-2.5 py-1.5 sm:w-64">
        <div class="mt-2 grid gap-1 sm:grid-cols-2">
            @foreach (\App\Support\Roles::ROLES as $value => $label)
                <label class="flex items-center gap-2"><input type="checkbox" wire:model="roles" value="{{ $value }}"> {{ $label }}</label>
            @endforeach
        </div>
        <p class="mt-1 text-[12px] text-mute">No roles at all removes staff access.</p>
        @foreach (['username', 'roles', 'roles.*'] as $f) @error($f) <p class="text-flag">{{ $message }}</p> @enderror @endforeach
        <button class="mt-2 rounded-lg bg-flag px-3 py-1.5 font-semibold text-white">Save roles</button>
    </form>

    <ul class="mt-3 divide-y divide-rule rounded-xl border border-rule bg-card text-sm">
        @forelse ($staff as $s)
            <li wire:key="staff-{{ $s->id }}" class="flex items-center gap-3 px-4 py-2.5">
                <a href="{{ route('admin.users.show', $s) }}" wire:navigate class="font-semibold hover:underline">{{ $s->name }}</a>
                <span class="text-mute">{{ '@'.$s->username }}</span>
                <span class="ml-auto flex gap-1">@foreach ($s->staffRoles() as $r)<span class="rounded bg-chip px-1.5 text-[11px] font-bold uppercase">{{ $r }}</span>@endforeach</span>
                <button type="button" wire:click="edit({{ $s->id }})" class="rounded-lg border border-rule px-2.5 py-0.5">Change</button>
            </li>
        @empty
            <li class="p-6 text-center text-mute">No staff yet.</li>
        @endforelse
    </ul>
</div>
