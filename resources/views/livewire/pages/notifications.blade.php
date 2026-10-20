<div>
    <h1 class="mb-3 font-display text-3xl font-bold">Notifications</h1>
    <ul class="divide-y divide-rule rounded-xl border border-rule bg-card">
        @forelse ($notifications as $n)
            @php($data = $n->data)
            <li @class(['px-4 py-3 text-sm', 'bg-chip/50' => ! $n->read_at])>
                <a href="{{ route('users.show', $data['actor']) }}" wire:navigate class="font-semibold">{{ '@'.$data['actor'] }}</a>
                {{ match ($data['kind']) { 'reply' => 'replied to your dispatch', 'quote' => 'quoted your dispatch', 'follow' => 'followed you', default => 'did something' } }}
                <span class="text-mute">· {{ $n->created_at->shortAbsoluteDiffForHumans() }}</span>
                @if ($data['dispatch_id'])
                    <a href="{{ route('dispatches.show', $data['dispatch_id']) }}" wire:navigate class="mt-1 block text-mute hover:text-ink">{{ $data['excerpt'] }}</a>
                @endif
            </li>
        @empty
            <li class="p-8 text-center text-mute">No notifications yet. File a dispatch and see who answers.</li>
        @endforelse
    </ul>
</div>
