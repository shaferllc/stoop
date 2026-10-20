@if ($reporting)
    <div class="fixed inset-0 z-30 grid place-items-center bg-black/40 p-4" wire:click.self="$set('reporting', null)">
        <form wire:submit="submitReport" class="w-full max-w-md rounded-xl border border-rule bg-card p-5" role="dialog" aria-labelledby="report-title">
            <h2 id="report-title" class="font-display text-xl font-bold">Report this dispatch</h2>
            <p class="mt-1 text-sm text-mute">It disappears for you right away. When several neighbors report it, it’s hidden for everyone until a moderator reviews it.</p>
            <fieldset class="mt-4 space-y-2">
                <legend class="sr-only">Reason</legend>
                @foreach (config('stoop.report_reasons') as $key => $label)
                    <label class="flex items-center gap-2 text-sm"><input type="radio" wire:model="reportReason" value="{{ $key }}"> {{ $label }}</label>
                @endforeach
            </fieldset>
            <label for="report-note" class="mt-4 block text-sm font-medium">Anything moderators should know? (optional)</label>
            <textarea id="report-note" wire:model="reportNote" rows="2" maxlength="500" class="mt-1 w-full rounded-lg border border-rule bg-paper p-2 text-sm"></textarea>
            @error('reportReason') <p class="text-sm text-flag">{{ $message }}</p> @enderror
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" wire:click="$set('reporting', null)" class="rounded-lg border border-rule px-3 py-1.5 text-sm">Cancel</button>
                <button type="submit" class="rounded-lg bg-flag px-3 py-1.5 text-sm font-semibold text-white">Report</button>
            </div>
        </form>
    </div>
@endif
