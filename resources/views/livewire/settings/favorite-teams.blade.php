<form wire:submit="save" class="my-8 space-y-3">
    <flux:checkbox.group wire:model="teams" :label="__('Your DC teams')" :description="__('They show as badges on your profile and count you on the team’s page.')">
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
            @foreach ($all as $key => $team)
                <flux:checkbox :value="$key" :label="$team['full']" />
            @endforeach
        </div>
    </flux:checkbox.group>
    <flux:button type="submit">{{ __('Save teams') }}</flux:button>
</form>
