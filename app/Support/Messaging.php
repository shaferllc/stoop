<?php

namespace App\Support;

use App\Events\MessageSent;
use App\Events\UserNotified;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Who can message whom, and sending. Rules:
 * - Both people need messages turned on, and neither can have blocked the other.
 * - You can message someone who follows you. Otherwise, if they accept message requests,
 *   your first message waits in their Requests until they accept it.
 * - Group members must follow the person adding them. Groups hold up to 100 people.
 * - Timeouts and bans stop sending, starting chats and managing groups.
 */
class Messaging
{
    /**
     * 'direct', 'request', or the reason it isn't allowed.
     */
    public static function access(User $from, User $to): string
    {
        if ($from->id === $to->id) {
            return 'You can’t message yourself.';
        }
        if (! $from->dms_on) {
            return 'Turn on messages in Settings → Safety first.';
        }
        if (! $to->dms_on || $to->is_bot) {
            return $to->name.' doesn’t accept messages.';
        }
        if (in_array($to->id, $from->hiddenUserIds(), true) || in_array($from->id, $to->hiddenUserIds(), true)) {
            return 'You can’t message '.$to->name.'.';
        }
        if ($to->following()->whereKey($from->id)->exists()) {
            return 'direct';
        }

        if (! $to->message_requests) {
            return $to->name.' only accepts messages from people they follow.';
        }

        // New accounts can't cold-message people, which slows down spam and harassment accounts.
        if ($from->created_at?->gt(now()->subHours(config('stoop.new_account_dm_hours'))) && ! $from->is_staff) {
            return 'New accounts can message people who follow them. Message requests open up after '.(config('stoop.new_account_dm_hours') / 24).' days.';
        }

        return 'request';
    }

    /**
     * Open (or reuse) a one-to-one conversation, or start a group.
     *
     * @param  array<int, User>  $people
     */
    public static function start(User $from, array $people, ?string $name = null): Conversation
    {
        if ($block = $from->postingBlock()) {
            throw ValidationException::withMessages(['to' => $block]);
        }

        $people = collect($people)->unique('id')->reject(fn ($u) => $u->id === $from->id)->values();

        if ($people->isEmpty()) {
            throw ValidationException::withMessages(['to' => 'Add at least one person.']);
        }

        if ($people->count() === 1 && ! $name) {
            $to = $people->first();
            $access = self::access($from, $to);
            if (! in_array($access, ['direct', 'request'], true)) {
                throw ValidationException::withMessages(['to' => $access]);
            }

            $existing = Conversation::where('is_group', false)
                ->whereHas('participants', fn ($q) => $q->whereKey($from->id))
                ->whereHas('participants', fn ($q) => $q->whereKey($to->id))
                ->first();

            return $existing ?? DB::transaction(function () use ($from, $to, $access) {
                $conversation = Conversation::create(['created_by' => $from->id]);
                $conversation->participants()->attach($from->id, ['accepted_at' => now(), 'last_read_at' => now()]);
                $conversation->participants()->attach($to->id, ['accepted_at' => $access === 'direct' ? now() : null]);

                return $conversation;
            });
        }

        if ($people->count() + 1 > Conversation::MAX_MEMBERS) {
            throw ValidationException::withMessages(['to' => 'Groups hold up to '.Conversation::MAX_MEMBERS.' people.']);
        }

        foreach ($people as $person) {
            self::checkCanAdd($from, $person);
        }

        return DB::transaction(function () use ($from, $people, $name) {
            $conversation = Conversation::create(['is_group' => true, 'name' => $name ? mb_substr($name, 0, 60) : null, 'created_by' => $from->id]);
            $conversation->participants()->attach($from->id, ['role' => 'admin', 'accepted_at' => now(), 'last_read_at' => now()]);
            foreach ($people as $person) {
                $conversation->participants()->attach($person->id, ['accepted_at' => now()]);
            }

            return $conversation;
        });
    }

    public static function checkCanAdd(User $adder, User $person): void
    {
        $access = self::access($adder, $person);

        if ($access !== 'direct') {
            throw ValidationException::withMessages(['to' => $access === 'request'
                ? $person->name.' has to follow you before you can add them to a group.'
                : $access]);
        }
    }

    /**
     * @param  array<int, mixed>  $photos
     */
    public static function send(User $from, Conversation $conversation, string $body, array $photos = [], ?int $replyTo = null): Message
    {
        $pivot = $conversation->pivotFor($from);
        abort_unless($pivot, 403);

        if ($block = $from->postingBlock()) {
            throw ValidationException::withMessages(['body' => $block]);
        }

        if (! $pivot->accepted_at) {
            throw ValidationException::withMessages(['body' => 'Accept this request to reply.']);
        }

        if (! $conversation->is_group) {
            $other = $conversation->participants->firstWhere('id', '!=', $from->id);
            if (! $other || in_array($other->id, $from->hiddenUserIds(), true) || in_array($from->id, $other->hiddenUserIds(), true) || ! $other->dms_on) {
                throw ValidationException::withMessages(['body' => 'You can’t message this person.']);
            }

            // Until a request is accepted, the sender gets one message.
            $theirs = $conversation->pivotFor($other);
            if (! $theirs->accepted_at && $conversation->messages()->where('user_id', $from->id)->exists()) {
                throw ValidationException::withMessages(['body' => 'Wait for '.$other->name.' to accept your request.']);
            }
        }

        Validator::make(['body' => $body, 'photos' => $photos], [
            'body' => [$photos ? 'nullable' : 'required', 'string', 'max:'.Message::MAX_LENGTH],
            'photos' => ['array', 'max:3'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ])->validate();

        if (Profanity::contains($body)) {
            throw ValidationException::withMessages(['body' => 'Stoop keeps it clean, in messages too: no swearing.']);
        }

        if (! RateLimiter::attempt('message:'.$from->id, 30, fn () => true)) {
            throw ValidationException::withMessages(['body' => 'Slow down a little. Try again in a minute.']);
        }

        $message = DB::transaction(function () use ($from, $conversation, $body, $photos, $replyTo) {
            $message = $conversation->messages()->create([
                'user_id' => $from->id,
                'body' => trim($body),
                'reply_to_id' => $replyTo && $conversation->messages()->whereKey($replyTo)->exists() ? $replyTo : null,
            ]);

            foreach ($photos as $photo) {
                $message->images()->create(Photos::store($photo, 'local', 'messages'));
            }

            $conversation->update(['last_message_at' => now()]);
            // A new message brings a stashed conversation back to the inbox.
            DB::table('conversation_participants')->where('conversation_id', $conversation->id)->update(['archived_at' => null]);
            DB::table('conversation_participants')->where(['conversation_id' => $conversation->id, 'user_id' => $from->id])->update(['last_read_at' => now()]);

            return $message;
        });

        Live::broadcast(new MessageSent($conversation->id, $message->id));

        foreach ($conversation->participants as $person) {
            if ($person->id !== $from->id && $person->pivot->accepted_at) {
                Live::broadcast(new UserNotified($person->id, 'message'));
                Live::push($person, $conversation->is_group ? $from->name.' in '.$conversation->titleFor($person) : $from->name, $message->body ?: 'Sent a photo', route('messages.show', $conversation));
            }
        }

        return $message;
    }

    public static function unreadCount(User $user): int
    {
        return DB::table('conversation_participants as p')
            ->join('conversations as c', 'c.id', '=', 'p.conversation_id')
            ->where('p.user_id', $user->id)
            ->whereNotNull('c.last_message_at')
            ->where(fn ($q) => $q->whereNull('p.last_read_at')->orWhereColumn('c.last_message_at', '>', 'p.last_read_at'))
            ->count();
    }
}
