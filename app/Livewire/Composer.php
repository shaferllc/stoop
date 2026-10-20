<?php

namespace App\Livewire;

use App\Models\Dispatch;
use App\Notifications\StoopActivity;
use App\Support\Civility;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class Composer extends Component
{
    #[Locked]
    public ?int $parentId = null;

    public ?int $quoteId = null;

    public string $type = 'dispatch';

    public string $body = '';

    public string $line = 'red';

    public ?string $stop = null;

    public string $happeningAt = '';

    /** @var array<int, string> */
    public array $options = ['', '', '', ''];

    public bool $warned = false;

    public function updatedBody(): void
    {
        $this->warned = false;
    }

    #[On('quote-dispatch')]
    public function startQuote(int $id): void
    {
        if ($this->parentId === null) {
            $this->quoteId = $id;
            $this->type = 'dispatch';
        }
    }

    public function file(): void
    {
        $user = Auth::user();

        if (! $user) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        if ($user->isMuted()) {
            $this->addError('body', 'You can’t post until '.$user->muted_until->format('M j, g:ia').'.');

            return;
        }

        $types = array_keys(config('stoop.types'));
        if (! $user->is_staff) {
            $types = array_diff($types, ['callout']);
        }

        $this->validate([
            'type' => ['required', Rule::in($types)],
            'body' => ['required', 'string', 'min:3', 'max:'.config('stoop.max_length')],
            'line' => ['required', Rule::in(array_keys(config('stoop.lines')))],
            'stop' => ['nullable', Rule::in(array_keys(config('stoop.stations')))],
            'happeningAt' => [Rule::requiredIf($this->type === 'happening' && ! $this->parentId), 'nullable', 'string', 'max:60'],
            'options.*' => ['nullable', 'string', 'max:50'],
        ], [], ['happeningAt' => 'when', 'options.*' => 'option']);

        $options = array_values(array_filter(array_map('trim', $this->options)));
        if ($this->type === 'poll' && ! $this->parentId && count($options) < 2) {
            $this->addError('options', 'A poll needs at least two options.');

            return;
        }

        if (! $this->warned && Civility::needsNudge($this->body)) {
            $this->warned = true;

            return;
        }

        if (! RateLimiter::attempt('dispatch:'.$user->id, config('stoop.posts_per_minute'), fn () => true)) {
            $this->addError('body', 'Slow down a little. Try again in a minute.');

            return;
        }

        $parent = $this->parentId ? Dispatch::visibleTo($user)->findOrFail($this->parentId) : null;
        $quoted = $this->quoteId ? Dispatch::visibleTo($user)->find($this->quoteId) : null;

        $dispatch = DB::transaction(function () use ($user, $parent, $quoted, $options) {
            $dispatch = $user->dispatches()->create([
                'parent_id' => $parent?->id,
                'quote_id' => $quoted?->id,
                'type' => $parent ? 'dispatch' : $this->type,
                'line' => $parent?->line ?? ($this->type === 'ask' ? 'yellow' : $this->line),
                'stop' => $parent ? null : $this->stop,
                'body' => trim($this->body),
                'happening_at' => ! $parent && $this->type === 'happening' ? $this->happeningAt : null,
                'status' => Civility::shouldHold($this->body) ? 'held' : 'approved',
            ]);

            if (! $parent && $this->type === 'poll') {
                $dispatch->options()->createMany(array_map(fn ($label) => ['label' => $label], $options));
            }

            if (! $parent && in_array($this->type, ['been', 'happening'], true)) {
                $user->marked()->attach($dispatch->id, ['kind' => $this->type === 'been' ? 'been' : 'going']);
            }

            return $dispatch;
        });

        if ($dispatch->status === 'approved') {
            if ($parent && $parent->user_id !== $user->id) {
                $parent->user->notify(new StoopActivity('reply', $user, $dispatch));
            }
            if ($quoted && $quoted->user_id !== $user->id) {
                $quoted->user->notify(new StoopActivity('quote', $user, $dispatch));
            }
        }

        $this->reset('body', 'happeningAt', 'options', 'quoteId', 'warned');
        $this->type = 'dispatch';
        $this->dispatch('dispatch-filed', id: $dispatch->id);

        Flux::toast($dispatch->status === 'held' ? 'Held for review. A moderator will check it before it goes up.' : ($parent ? 'Reply posted.' : 'Dispatch filed.'));
    }

    public function render(): View
    {
        return view('livewire.composer', [
            'quoted' => $this->quoteId ? Dispatch::with('user')->find($this->quoteId) : null,
        ]);
    }
}
