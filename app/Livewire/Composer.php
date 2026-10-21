<?php

namespace App\Livewire;

use App\Actions\FileDispatch;
use App\Exceptions\NudgeRequired;
use App\Models\CityEvent;
use App\Models\Dispatch;
use App\Models\Place;
use App\Models\User;
use App\Support\Achievements;
use App\Support\Settings;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
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

    public ?int $placeId = null;

    public bool $showPlace = false;

    public string $placeQuery = '';

    public bool $asBusiness = false;

    public string $sponsor = '';

    public ?int $eventId = null;

    public ?int $rating = null;

    public ?string $snowInches = null;

    public ?string $plowed = null;

    public function selectPlace(?int $id): void
    {
        $this->placeId = $id;
        $this->placeQuery = '';
        $this->asBusiness = false;
    }

    /**
     * "Join the conversation" links from article pages arrive with ?share=<article url>.
     */
    public function mount(): void
    {
        $share = request()->query('share');

        if (! $this->parentId && is_string($share) && filter_var($share, FILTER_VALIDATE_URL) && str_starts_with($share, 'http')) {
            $this->body = ' '.$share;
        }
    }

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

        $this->resetErrorBag();
        $badgesBefore = Achievements::unlocked($user);

        try {
            $dispatch = FileDispatch::handle($user, [
                ...$this->only(['type', 'body', 'line', 'stop', 'happeningAt', 'options', 'pollHours', 'publishAt', 'photos', 'alts', 'safetyWhen', 'safetyBlock', 'safetyPerson', 'quoteId', 'placeId', 'asBusiness', 'sponsor', 'eventId', 'rating', 'snowInches', 'plowed']),
                'parentId' => $this->parentId,
            ], confirmed: $this->warned);
        } catch (NudgeRequired) {
            $this->warned = true;

            return;
        }

        $this->reset('body', 'happeningAt', 'options', 'quoteId', 'warned', 'publishAt', 'photos', 'alts', 'pollHours', 'safetyWhen', 'safetyBlock', 'safetyPerson', 'placeId', 'placeQuery', 'asBusiness', 'sponsor', 'showPlace', 'eventId', 'rating', 'snowInches', 'plowed');
        $this->type = 'dispatch';
        $this->dispatch('dispatch-filed', id: $dispatch->id);

        Flux::toast(match ($dispatch->status) {
            'held' => 'Held for review. A moderator will check it before it goes up.',
            'pending' => 'Filed. It goes up once it passes a quick check.',
            'scheduled' => 'Scheduled for '.$dispatch->publish_at->format('M j, g:ia').'.',
            default => $this->parentId ? 'Reply posted.' : 'Dispatch filed.',
        });

        $unlocked = array_diff_key(Achievements::unlocked($user->fresh()), $badgesBefore);
        foreach ($unlocked as $key => $badge) {
            Flux::toast(heading: $key === 'stand-right' ? 'Stand right, walk left' : 'Badge unlocked', text: $badge['name'].': '.$badge['description'], variant: 'success');
        }
        if ($unlocked) {
            $this->dispatch('confetti');
        }
    }

    public function render(): View
    {
        $q = trim($this->placeQuery);

        return view('livewire.composer', [
            'quoted' => $this->quoteId ? Dispatch::with('user')->find($this->quoteId) : null,
            'place' => $this->placeId ? Place::find($this->placeId) : null,
            'events' => $this->parentId ? collect() : CityEvent::where('kind', 'event')->happening()->orderBy('ends_at')->get(),
            'snowDay' => Settings::snowDay(),
            'placeResults' => mb_strlen($q) >= 2
                ? Place::where('name', 'like', '%'.$q.'%')->orderByRaw('list_rank is null, list_rank')->take(6)->get()
                : collect(),
        ]);
    }
}
