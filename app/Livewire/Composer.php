<?php

namespace App\Livewire;

use App\Actions\AnnounceDispatch;
use App\Models\Dispatch;
use App\Models\User;
use App\Support\Civility;
use App\Support\LinkPreviews;
use App\Support\Mentions;
use App\Support\Photos;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

class Composer extends Component
{
    use WithFileUploads;

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

    public int $pollHours = 24;

    public string $publishAt = '';

    /** @var array<int, mixed> */
    public array $photos = [];

    /** @var array<int, string> */
    public array $alts = [];

    public bool $warned = false;

    /** Crime & safety form: when it happened, the block, and an optional description of a person. */
    public string $safetyWhen = '';

    public string $safetyBlock = '';

    public string $safetyPerson = '';

    public function updatedBody(): void
    {
        $this->warned = false;
    }

    public function removePhoto(int $index): void
    {
        array_splice($this->photos, $index, 1);
        array_splice($this->alts, $index, 1);
    }

    #[On('quote-dispatch')]
    public function startQuote(int $id): void
    {
        if ($this->parentId === null) {
            $this->quoteId = $id;
            $this->type = 'dispatch';
        }
    }

    public function acceptRules(): void
    {
        Auth::user()?->forceFill(['accepted_rules_at' => now()])->save();
    }

    /**
     * People to suggest after typing @: the thread's people first, then who you follow, then everyone.
     *
     * @return array<int, array{username: string, name: string}>
     */
    public function mentionSuggestions(string $term = ''): array
    {
        $user = Auth::user();
        if (! $user) {
            return [];
        }

        $term = preg_replace('/[^A-Za-z0-9_ ]/', '', $term);
        $hidden = [...$user->hiddenUserIds(), $user->id];

        $priority = collect();
        if ($this->parentId && $parent = Dispatch::find($this->parentId)) {
            $priority = Dispatch::where('parent_id', $parent->id)->pluck('user_id')->prepend($parent->user_id);
        }

        $query = User::whereNotIn('id', $hidden)->select('id', 'username', 'name');
        $matches = $term === ''
            ? $query->whereIn('id', $priority->merge($user->following()->pluck('users.id')))->take(20)->get()
            : $query->where(fn ($q) => $q->where('username', 'like', $term.'%')->orWhere('name', 'like', '%'.$term.'%'))->take(20)->get();

        return $matches->sortBy(fn ($u) => [($i = $priority->search($u->id)) === false ? 999 : $i, $u->username])
            ->take(6)->map(fn ($u) => ['username' => $u->username, 'name' => $u->name])->values()->all();
    }

    public function file(): void
    {
        $user = Auth::user();

        if (! $user) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        if (! $user->accepted_rules_at) {
            $this->addError('body', 'Read the house rules above first.');

            return;
        }

        if ($block = $user->postingBlock()) {
            $this->addError('body', $block);

            return;
        }

        $topLevel = ! $this->parentId;
        $types = array_keys(config('stoop.types'));
        if (! $user->is_staff) {
            $types = array_diff($types, ['callout']);
        }

        $this->validate([
            'type' => ['required', Rule::in($types)],
            'body' => [Rule::requiredIf(! $this->photos), 'nullable', 'string', 'min:3', 'max:'.config('stoop.max_length')],
            'line' => ['required', Rule::in(array_keys(config('stoop.lines')))],
            'stop' => ['nullable', Rule::in(array_keys(config('stoop.stations')))],
            'happeningAt' => [Rule::requiredIf($this->type === 'happening' && $topLevel), 'nullable', 'string', 'max:60'],
            'options.*' => ['nullable', 'string', 'max:50'],
            'pollHours' => [Rule::in([1, 24, 72, 168])],
            'publishAt' => ['nullable', 'date', 'after:now', 'before:'.now()->addDays(30)->toDateTimeString()],
            'photos' => ['array', 'max:3'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:10240', 'dimensions:max_width=6000,max_height=6000'],
            'alts.*' => ['nullable', 'string', 'max:1000'],
            'safetyWhen' => [Rule::requiredIf($this->type === 'safety' && $topLevel), 'nullable', 'string', 'max:60'],
            'safetyBlock' => ['nullable', 'string', 'max:80'],
            'safetyPerson' => ['nullable', 'string', 'max:280'],
        ], [], ['happeningAt' => 'when', 'options.*' => 'option', 'publishAt' => 'scheduled time', 'photos.*' => 'photo', 'safetyWhen' => 'when it happened']);

        if ($this->type === 'safety' && $topLevel) {
            if (! $this->stop) {
                $this->addError('stop', 'Pick the nearest stop so neighbors know where this happened.');

                return;
            }

            if ($this->safetyPerson !== '' && Civility::isIdentityOnly($this->safetyPerson)) {
                $this->addError('safetyPerson', 'Describe clothing, height, a vehicle or what they did. Race or gender alone doesn’t help anyone identify a person, and it isn’t allowed here.');

                return;
            }
        }

        if ($this->parentId && $reason = $this->replyBlock($user)) {
            $this->addError('body', $reason);

            return;
        }

        $options = array_values(array_unique(array_filter(array_map('trim', $this->options))));
        if ($this->type === 'poll' && $topLevel && count($options) < 2) {
            $this->addError('options', 'A poll needs at least two different options.');

            return;
        }

        $scheduled = $topLevel && $this->publishAt !== '';
        if ($scheduled && $user->dispatches()->where('status', 'scheduled')->count() >= 10) {
            $this->addError('publishAt', 'You already have 10 scheduled dispatches.');

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
        $publishAt = $scheduled ? Carbon::parse($this->publishAt) : null;
        $url = Mentions::firstUrl($this->body);
        $preview = $url ? LinkPreviews::for($url) : null;

        $dispatch = DB::transaction(function () use ($user, $parent, $quoted, $options, $publishAt, $preview) {
            $dispatch = $user->dispatches()->create([
                'parent_id' => $parent?->id,
                'quote_id' => $quoted?->id,
                'type' => $parent ? 'dispatch' : $this->type,
                'line' => $parent?->line ?? ($this->type === 'ask' ? 'yellow' : $this->line),
                'stop' => $parent ? null : $this->stop,
                'body' => trim($this->body),
                'happening_at' => ! $parent && $this->type === 'happening' ? $this->happeningAt : null,
                'status' => $publishAt ? 'scheduled' : (Civility::shouldHold($this->body) ? 'held' : 'approved'),
                'publish_at' => $publishAt,
                'closes_at' => ! $parent && $this->type === 'poll' ? ($publishAt ?? now())->copy()->addHours($this->pollHours) : null,
                'link_preview_id' => $preview?->id,
                'safety' => ! $parent && $this->type === 'safety'
                    ? array_filter(['when' => $this->safetyWhen, 'block' => $this->safetyBlock, 'person' => $this->safetyPerson])
                    : null,
            ]);

            if (! $parent && $this->type === 'poll') {
                $dispatch->options()->createMany(array_map(fn ($label) => ['label' => $label], $options));
            }

            foreach ($this->photos as $i => $photo) {
                $dispatch->images()->create([...Photos::store($photo), 'alt' => $this->alts[$i] ?? null]);
            }

            if (! $parent && in_array($this->type, ['been', 'happening'], true)) {
                $user->marked()->attach($dispatch->id, ['kind' => $this->type === 'been' ? 'been' : 'going']);
            }

            return $dispatch;
        });

        AnnounceDispatch::run($dispatch);

        if ($parent) {
            $this->checkPileOn($parent);
        }

        $this->reset('body', 'happeningAt', 'options', 'quoteId', 'warned', 'publishAt', 'photos', 'alts', 'pollHours', 'safetyWhen', 'safetyBlock', 'safetyPerson');
        $this->type = 'dispatch';
        $this->dispatch('dispatch-filed', id: $dispatch->id);

        Flux::toast(match ($dispatch->status) {
            'held' => 'Held for review. A moderator will check it before it goes up.',
            'scheduled' => 'Scheduled for '.$dispatch->publish_at->format('M j, g:ia').'.',
            default => $parent ? 'Reply posted.' : 'Dispatch filed.',
        });
    }

    /**
     * Why this person can't reply to the parent dispatch, or null when they can.
     */
    protected function replyBlock(User $user): ?string
    {
        $parent = Dispatch::find($this->parentId);

        if (! $parent || $parent->user_id === $user->id) {
            return null;
        }

        if ($parent->replies_locked_until?->isFuture()) {
            return 'Replies are paused on this thread for a little while.';
        }

        if ($parent->user->replies_from === 'following' && ! $parent->user->following()->whereKey($user->id)->exists()) {
            return 'Only people '.$parent->user->name.' follows can reply.';
        }

        return null;
    }

    /**
     * A sudden flood of replies from people who don't follow the author pauses the thread
     * for an hour and puts it in front of moderators.
     */
    protected function checkPileOn(Dispatch $parent): void
    {
        if ($parent->flagged_at) {
            return;
        }

        $strangers = $parent->replies()
            ->where('created_at', '>=', now()->subMinutes(10))
            ->whereNotIn('user_id', $parent->user->followers()->select('users.id'))
            ->where('user_id', '!=', $parent->user_id)
            ->distinct()->count('user_id');

        if ($strangers >= config('stoop.pile_on_threshold')) {
            $parent->forceFill(['replies_locked_until' => now()->addHour(), 'flagged_at' => now()])->save();
        }
    }

    public function render(): View
    {
        return view('livewire.composer', [
            'quoted' => $this->quoteId ? Dispatch::with('user')->find($this->quoteId) : null,
        ]);
    }
}
