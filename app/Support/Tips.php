<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\Dispatch;
use App\Models\Message;
use App\Models\Tip;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Tips to the newsroom. They go to the editors' inbox and nowhere else: never into a feed,
 * search or the public API. Editors can write back through Stoop messages, but only when
 * the tipster said that's OK.
 */
class Tips
{
    public const PER_HOUR = 5;

    /**
     * @param  array{body?: string, link?: ?string, dispatchId?: ?int, contactOk?: bool, photos?: array<int, mixed>}  $input
     *
     * @throws ValidationException
     */
    public static function submit(User $user, array $input): Tip
    {
        $input = ['body' => '', 'link' => null, 'dispatchId' => null, 'contactOk' => false, 'photos' => [], ...array_filter($input, fn ($v) => $v !== null)];
        $input['body'] = trim((string) $input['body']);
        $input['link'] = trim((string) $input['link']) ?: null;
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message]);

        if ($block = $user->postingBlock()) {
            $fail('body', $block);
        }

        Validator::make($input, [
            'body' => ['required', 'string', 'min:10', 'max:2000'],
            'link' => ['nullable', 'url:http,https', 'max:500'],
            'dispatchId' => ['nullable', 'integer'],
            'photos' => ['array', 'max:3'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:10240', 'dimensions:max_width=6000,max_height=6000'],
        ], [], ['body' => 'tip', 'photos.*' => 'photo'])->validate();

        if (Profanity::contains($input['body'])) {
            $fail('body', 'Stoop keeps it clean, tips too: no swearing. Edit it and send it again.');
        }

        $dispatch = $input['dispatchId'] ? Dispatch::visibleTo($user)->find($input['dispatchId']) : null;
        if ($input['dispatchId'] && ! $dispatch) {
            $fail('dispatchId', 'That dispatch isn’t available.');
        }

        if (! RateLimiter::attempt('tip:'.$user->id, self::PER_HOUR, fn () => true, 3600)) {
            $fail('body', 'You’ve sent a lot of tips this hour. Try again later.');
        }

        // Photos stay on the private disk; only staff can open them.
        $photos = array_map(fn ($photo) => Photos::store($photo, 'local', 'tips')['path'], $input['photos']);

        return Tip::create([
            'user_id' => $user->id,
            'body' => $input['body'],
            'link' => $input['link'],
            'dispatch_id' => $dispatch?->id,
            'contact_ok' => (bool) $input['contactOk'],
            'photos' => $photos ?: null,
            'screening' => self::screen($input['body']),
            'status' => 'new',
        ]);
    }

    /**
     * OpenAI's moderation scores, so editors see a warning on abusive tips. Never blocks a tip:
     * tips are never public, and a failed check just leaves no scores.
     *
     * @return array<string, mixed>|null
     */
    protected static function screen(string $body): ?array
    {
        if (! OpenAiModeration::enabled()) {
            return null;
        }

        try {
            $result = OpenAiModeration::check((new Dispatch(['body' => $body]))->setRelation('images', collect()));

            return ['flagged' => $result['flagged']];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Write back to the tipster in Stoop messages. They said it's OK to contact them, so this
     * skips the message-request step, but blocks and turned-off messages still stop it.
     *
     * @throws ValidationException
     */
    public static function reply(Tip $tip, User $editor, string $body): Message
    {
        $body = trim($body);
        if (! $tip->contact_ok) {
            throw ValidationException::withMessages(['reply' => 'This person didn’t say it’s OK to contact them.']);
        }
        if (mb_strlen($body) < 2 || mb_strlen($body) > 1500) {
            throw ValidationException::withMessages(['reply' => 'Write a reply of up to 1,500 characters.']);
        }

        $tipster = $tip->user;
        if ($tipster->id === $editor->id) {
            throw ValidationException::withMessages(['reply' => 'That’s your own tip.']);
        }
        if (! $tipster->dms_on || in_array($tipster->id, $editor->hiddenUserIds(), true) || in_array($editor->id, $tipster->hiddenUserIds(), true)) {
            throw ValidationException::withMessages(['reply' => '@'.$tipster->username.' can’t get messages from you. Their tip still counts.']);
        }

        $conversation = Conversation::where('is_group', false)
            ->whereHas('participants', fn ($q) => $q->whereKey($editor->id))
            ->whereHas('participants', fn ($q) => $q->whereKey($tipster->id))
            ->first();

        $conversation ??= DB::transaction(function () use ($editor, $tipster) {
            $conversation = Conversation::create(['created_by' => $editor->id]);
            $conversation->participants()->attach($editor->id, ['accepted_at' => now(), 'last_read_at' => now()]);
            $conversation->participants()->attach($tipster->id, ['accepted_at' => now()]);

            return $conversation;
        });

        DB::table('conversation_participants')->where('conversation_id', $conversation->id)->whereNull('accepted_at')->update(['accepted_at' => now()]);

        $message = Messaging::send($editor, $conversation->load('participants'), 'About your tip (“'.Str::limit($tip->body, 60).'”): '.$body);
        Audit::log('tip.replied', $tip);

        return $message;
    }
}
