<?php

namespace App\Support;

use App\Actions\FileDispatch;
use App\Models\Dispatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What staff need to keep the house bots honest: their recent answers, a way to flag a
 * wrong one and reply with a correction as the bot, and @stoop's knowledge base.
 */
class BotConsole
{
    /**
     * @return Collection<int, Dispatch>
     */
    public static function answers(): Collection
    {
        return Dispatch::with(['user', 'parent.user'])
            ->whereIn('user_id', DB::table('users')->where('is_bot', true)->whereIn('username', array_keys(Bots::ACCOUNTS))->select('id'))
            ->whereNotNull('parent_id')
            ->latest('id')->take(50)->get();
    }

    public static function answer(int $id): Dispatch
    {
        $answer = Dispatch::with('user')->findOrFail($id);
        abort_unless($answer->user->is_bot && isset(Bots::ACCOUNTS[$answer->user->username]) && $answer->parent_id, 404);

        return $answer;
    }

    public static function markWrong(Dispatch $answer): void
    {
        $answer->forceFill(['details' => [...($answer->details ?? []), 'marked_wrong_at' => now()->toIso8601String()]])->save();
        Audit::log('bot.answer_marked_wrong', $answer);
    }

    /**
     * Replies under the wrong answer, as the same bot, and marks it wrong.
     */
    public static function correct(Dispatch $answer, string $body): Dispatch
    {
        $reply = FileDispatch::handle(Bots::account($answer->user->username), ['parentId' => $answer->id, 'body' => $body], confirmed: true);
        if (empty($answer->details['marked_wrong_at'])) {
            self::markWrong($answer);
        }
        Audit::log('bot.correction_posted', $reply);

        return $reply;
    }

    /**
     * @return Collection<int, object>
     */
    public static function knowledge(): Collection
    {
        return DB::table('bot_knowledge')->orderBy('section')->orderBy('id')->get(['id', 'section', 'body']);
    }

    public static function saveEntry(?int $id, string $section, string $body): void
    {
        $values = ['section' => trim($section), 'body' => trim($body), 'updated_at' => now()];
        $id
            ? DB::table('bot_knowledge')->where('id', $id)->update($values)
            : $id = DB::table('bot_knowledge')->insertGetId($values + ['created_at' => now()]);
        Audit::log('bot.knowledge_saved', null, ['id' => $id, 'section' => $section]);
    }

    public static function deleteEntry(int $id): void
    {
        DB::table('bot_knowledge')->where('id', $id)->delete();
        Audit::log('bot.knowledge_deleted', null, ['id' => $id]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function entryRules(): array
    {
        return ['section' => ['required', 'string', 'max:80'], 'body' => ['required', 'string', 'max:1000']];
    }
}
