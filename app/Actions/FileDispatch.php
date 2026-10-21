<?php

namespace App\Actions;

use App\Exceptions\NudgeRequired;
use App\Jobs\ModerateDispatch;
use App\Jobs\RunBots;
use App\Models\CityEvent;
use App\Models\Dispatch;
use App\Models\Place;
use App\Models\User;
use App\Support\Challenges;
use App\Support\Civility;
use App\Support\Later;
use App\Support\LinkPreviews;
use App\Support\LostAndFound;
use App\Support\Mentions;
use App\Support\ModerationModel;
use App\Support\OpenAiModeration;
use App\Support\OpenHouses;
use App\Support\Photos;
use App\Support\Profanity;
use App\Support\Settings;
use App\Support\SignupRisk;
use App\Support\WatchPhrases;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates a dispatch. The web composer, the API and the terminal script all go through
 * here, so validation, moderation and rate limits are the same everywhere.
 */
class FileDispatch
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException|NudgeRequired
     */
    public static function handle(User $user, array $input, bool $confirmed = false): Dispatch
    {
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message]);

        $input = [
            'type' => 'dispatch', 'body' => '', 'line' => 'red', 'stop' => null, 'happeningAt' => '',
            'options' => [], 'pollHours' => 24, 'publishAt' => '', 'photos' => [], 'alts' => [],
            'safetyWhen' => '', 'safetyBlock' => '', 'safetyPerson' => '', 'parentId' => null, 'quoteId' => null, 'placeId' => null, 'asBusiness' => false, 'sponsor' => '', 'eventId' => null, 'rating' => null, 'snowInches' => null, 'plowed' => null, 'openHouse' => [], 'happeningStarts' => '', 'lostKind' => null, 'lostStatus' => null, 'quoteOk' => false,
            ...array_filter($input, fn ($v) => $v !== null),
        ];
        $input['body'] = trim((string) $input['body']);
        $topLevel = ! $input['parentId'];

        if (! $user->accepted_rules_at) {
            $fail('body', 'Read and accept the house rules first.');
        }

        if ($block = $user->postingBlock()) {
            $fail('body', $block);
        }

        $types = array_keys(config('stoop.types'));
        if (! $user->is_staff) {
            $types = array_diff($types, ['callout']);
        }
        if (! Settings::snowDay()) {
            $types = array_diff($types, ['snow']);
        }

        Validator::make($input, [
            'type' => ['required', Rule::in($types)],
            'body' => [Rule::requiredIf(! $input['photos']), 'nullable', 'string', 'min:3', 'max:'.config('stoop.max_length')],
            'line' => ['required', Rule::in(array_keys(config('stoop.lines')))],
            'stop' => ['nullable', Rule::in(array_keys(config('stoop.stations')))],
            'happeningAt' => [Rule::requiredIf($input['type'] === 'happening' && $topLevel && $input['happeningStarts'] === ''), 'nullable', 'string', 'max:60'],
            'happeningStarts' => ['nullable', 'date'],
            'lostKind' => [Rule::requiredIf($input['type'] === 'lost' && $topLevel), 'nullable', Rule::in(array_keys(LostAndFound::KINDS))],
            'lostStatus' => [Rule::requiredIf($input['type'] === 'lost' && $topLevel), 'nullable', Rule::in(array_keys(LostAndFound::STATUSES))],
            'options' => ['array', 'max:4'],
            'options.*' => ['nullable', 'string', 'max:50'],
            'pollHours' => [Rule::in([1, 24, 72, 168])],
            'publishAt' => ['nullable', 'date', 'after:now', 'before:'.now()->addDays(30)->toDateTimeString()],
            'photos' => ['array', 'max:3'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:10240', 'dimensions:max_width=6000,max_height=6000'],
            'alts.*' => ['nullable', 'string', 'max:1000'],
            'safetyWhen' => [Rule::requiredIf($input['type'] === 'safety' && $topLevel), 'nullable', 'string', 'max:60'],
            'safetyBlock' => ['nullable', 'string', 'max:80'],
            'safetyPerson' => ['nullable', 'string', 'max:280'],
            'placeId' => ['nullable', 'integer', 'exists:places,id'],
            'sponsor' => ['nullable', 'string', 'max:120'],
            'eventId' => ['nullable', 'integer'],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'snowInches' => [Rule::requiredIf($input['type'] === 'snow' && $topLevel), 'nullable', 'numeric', 'between:0,60'],
            'plowed' => [Rule::requiredIf($input['type'] === 'snow' && $topLevel), 'nullable', Rule::in(['yes', 'partly', 'no'])],
        ], [], ['lostKind' => 'pet or item', 'lostStatus' => 'lost or found', 'happeningStarts' => 'start time', 'snowInches' => 'snow total', 'plowed' => 'plowing', 'happeningAt' => 'when', 'options.*' => 'option', 'publishAt' => 'scheduled time', 'photos.*' => 'photo', 'safetyWhen' => 'when it happened'])->validate();

        $typed = [$input['body'], $input['happeningAt'], $input['safetyWhen'], $input['safetyBlock'], $input['safetyPerson'], ...$input['options'], ...$input['alts']];
        if (collect($typed)->contains(fn ($text) => Profanity::contains((string) $text))) {
            $fail('body', 'Stoop keeps it clean: no swearing. Edit it and post again.');
        }

        $options = array_values(array_unique(array_filter(array_map('trim', $input['options']))));
        if ($input['type'] === 'poll' && $topLevel && count($options) < 2) {
            $fail('options', 'A poll needs at least two different options.');
        }

        if ($input['type'] === 'safety' && $topLevel) {
            if (! $input['stop']) {
                $fail('stop', 'Pick the nearest stop so neighbors know where this happened.');
            }
            if ($input['safetyPerson'] !== '' && Civility::isIdentityOnly($input['safetyPerson'])) {
                $fail('safetyPerson', 'Describe clothing, height, a vehicle or what they did. Race or gender alone doesn’t help anyone identify a person, and it isn’t allowed here.');
            }
        }

        $openHouse = $input['type'] === 'openhouse' && $topLevel ? OpenHouses::details((array) $input['openHouse'], $input['stop']) : [];

        $parent = $input['parentId'] ? Dispatch::visibleTo($user)->find($input['parentId']) : null;
        if ($input['parentId'] && ! $parent) {
            $fail('parentId', 'That dispatch isn’t available.');
        }
        if ($parent && $reason = self::replyBlock($user, $parent)) {
            $fail('body', $reason);
        }

        $place = $topLevel && $input['placeId'] ? Place::find($input['placeId']) : null;
        if ($input['type'] === 'been' && $topLevel && ! $place) {
            $fail('placeId', 'Pick the place you went to.');
        }
        if ($input['asBusiness'] && $place?->owner_id !== $user->id) {
            $fail('placeId', 'Only the verified owner can post as '.($place?->name ?? 'this place').'.');
        }
        if ($input['sponsor'] !== '' && ! ($user->is_staff && $input['type'] === 'callout')) {
            $fail('sponsor', 'Only staff can label a callout as sponsored.');
        }

        $event = $topLevel && $input['eventId'] ? CityEvent::where('kind', 'event')->happening()->find($input['eventId']) : null;
        if ($input['eventId'] && ! $event) {
            $fail('eventId', 'That event isn’t happening right now.');
        }
        if ($input['type'] === 'lost' && $topLevel) {
            if (! $input['stop']) {
                $fail('stop', 'Pick the stop it was last seen near.');
            }
            if (collect([$input['body'], ...$input['alts']])->contains(fn ($text) => LostAndFound::hasContactInfo((string) $text))) {
                $fail('body', 'Leave out phone numbers and emails. Neighbors can reply or message you instead.');
            }
        }
        if ($input['type'] === 'snow' && $topLevel && ! $input['stop']) {
            $fail('stop', 'Pick the nearest stop so neighbors know where the snow is.');
        }

        $scheduled = $topLevel && $input['publishAt'] !== '';
        if ($scheduled && $user->dispatches()->where('status', 'scheduled')->count() >= 10) {
            $fail('publishAt', 'You already have 10 scheduled dispatches.');
        }

        if (! $confirmed && Civility::needsNudge($input['body'])) {
            throw new NudgeRequired;
        }

        if (! $user->is_staff && ! $user->is_bot && $user->created_at?->gt(now()->subWeek())
            && $user->dispatches()->where('created_at', '>=', now()->subDay())->count() >= config('stoop.new_account_daily_posts')) {
            $fail('body', 'New accounts can post '.config('stoop.new_account_daily_posts').' times a day for their first week. Try again tomorrow.');
        }

        if (! RateLimiter::attempt('dispatch:'.$user->id, config('stoop.posts_per_minute'), fn () => true)) {
            $fail('body', 'Slow down a little. Try again in a minute.');
        }

        $quoted = $input['quoteId'] && $topLevel ? Dispatch::visibleTo($user)->find($input['quoteId']) : null;
        $publishAt = $scheduled ? Carbon::parse($input['publishAt']) : null;
        $url = Mentions::firstUrl($input['body']);
        $preview = $url ? LinkPreviews::for($url) : null;
        $type = $parent ? 'dispatch' : $input['type'];

        $details = array_filter([
            'rating' => $event?->rating_label && $input['rating'] ? (int) $input['rating'] : null,
            'snow_inches' => $type === 'snow' ? (float) $input['snowInches'] : null,
            'plowed' => $type === 'snow' ? $input['plowed'] : null,
            'lost_kind' => $type === 'lost' ? $input['lostKind'] : null,
            'lost_status' => $type === 'lost' ? $input['lostStatus'] : null,
            // A reply to a newsroom callout that the newsroom may quote in a story.
            'quote_ok' => $parent?->type === 'callout' && $input['quoteOk'] ? true : null,
        ], fn ($v) => $v !== null) + ($type === 'openhouse' ? $openHouse : []);

        // The calendar start time is typed in DC time.
        $startsAt = $type === 'happening' && $input['happeningStarts'] !== '' ? CarbonImmutable::parse($input['happeningStarts'], config('stoop.timezone'))->utc() : null;

        $dispatch = DB::transaction(function () use ($user, $input, $parent, $quoted, $options, $publishAt, $preview, $type, $place, $event, $details, $startsAt) {
            $dispatch = $user->dispatches()->create([
                'parent_id' => $parent?->id,
                'quote_id' => $quoted?->id,
                'type' => $type,
                'line' => $parent?->line ?? match ($type) {
                    'ask' => 'yellow',
                    'openhouse' => 'green',
                    default => $input['line'],
                },
                'body' => $input['body'],
                'happening_at' => $type === 'happening' ? ($input['happeningAt'] ?: $startsAt?->timezone(config('stoop.timezone'))->format('D M j · g:ia')) : null,
                'status' => $publishAt ? 'scheduled' : self::initialStatus($user, $input['body']),
                'publish_at' => $publishAt,
                'closes_at' => $type === 'poll' ? ($publishAt ?? now())->copy()->addHours((int) $input['pollHours']) : null,
                'link_preview_id' => $preview?->id,
                'place_id' => $place?->id,
                'stop' => $parent ? null : ($input['stop'] ?: $place?->stop),
                'as_business' => (bool) $input['asBusiness'],
                'sponsor' => $input['sponsor'] ?: null,
                'event_id' => $event?->id,
                'details' => $details ?: null,
                'safety' => $type === 'safety'
                    ? array_filter(['when' => $input['safetyWhen'], 'block' => $input['safetyBlock'], 'person' => $input['safetyPerson']])
                    : null,
            ]);

            if ($startsAt) {
                $dispatch->happening()->create(['starts_at' => $startsAt]);
            }

            if ($type === 'poll') {
                $dispatch->options()->createMany(array_map(fn ($label) => ['label' => $label], $options));
            }

            foreach ($input['photos'] as $i => $photo) {
                $dispatch->images()->create([...Photos::store($photo), 'alt' => $input['alts'][$i] ?? null]);
            }

            if (in_array($type, ['been', 'happening'], true)) {
                $user->marked()->attach($dispatch->id, ['kind' => $type === 'been' ? 'been' : 'going']);
            }

            return $dispatch;
        });

        // Coded or local hate from staff's watch list: hold it and show moderators what it means.
        if ($watch = WatchPhrases::matches(implode("\n", array_map('strval', $typed)))) {
            $dispatch->forceFill(['status' => 'held', 'screening' => ['watch' => $watch]])->save();
        }

        if ($parent) {
            self::checkPileOn($parent);
        }

        if ($type === 'been') {
            Challenges::check($user);
        }

        if ($dispatch->status === 'pending') {
            Later::run(new ModerateDispatch($dispatch));
        } elseif ($dispatch->status === 'approved') {
            AnnounceDispatch::run($dispatch);
            Later::run(new RunBots($dispatch));
        }

        return $dispatch->fresh();
    }

    /**
     * Held if it matches a held pattern or comes from a risky new account; pending the automatic check when it's on, for
     * everyone but Stoop's house accounts (staff too); otherwise approved.
     */
    protected static function initialStatus(User $user, string $body): string
    {
        if (Civility::shouldHold($body) || SignupRisk::holds($user)) {
            return 'held';
        }

        $screened = ModerationModel::enabled() || OpenAiModeration::enabled();

        return $screened && ! $user->isHouseAccount() ? 'pending' : 'approved';
    }

    /**
     * Why this person can't reply to the parent dispatch, or null when they can.
     */
    protected static function replyBlock(User $user, Dispatch $parent): ?string
    {
        if ($parent->user_id === $user->id) {
            return null;
        }

        // A pause on the thread covers replies to replies too.
        if ($parent->replies_locked_until?->isFuture() || ($parent->parent_id && $parent->threadRoot()->replies_locked_until?->isFuture())) {
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
    protected static function checkPileOn(Dispatch $parent): void
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
}
