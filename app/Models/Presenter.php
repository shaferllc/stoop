<?php

namespace App\Models;

use App\Support\Ads;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A "Presented by" sponsor on a house bot's scheduled posts or on a game. At most one runs
 * per target at a time. Bots never carry it on replies or safety posts (see stamp()).
 */
#[Fillable(['target', 'sponsor', 'logo_path', 'url', 'starts_on', 'ends_on', 'created_by'])]
class Presenter extends Model
{
    /** The only bots that can be sponsored. Never @calm or @sentinel. */
    public const BOTS = ['weather', 'metro', 'gameday', 'quizmaster', 'recap', 'weekend'];

    public const GAMES = ['daily' => 'The Daily', 'linecup' => 'The Line Cup'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function scopeRunning(Builder $query): void
    {
        $today = now(config('stoop.timezone'))->toDateString();
        $query->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>=', $today);
    }

    /**
     * The running sponsor for any of these targets.
     *
     * @param  string|array<int, string>  $target
     */
    public static function for(string|array $target): ?self
    {
        return static::running()->whereIn('target', (array) $target)->first();
    }

    /**
     * The running sponsor for a game page or Play card, counting the impression.
     *
     * @param  string|array<int, string>  $target
     */
    public static function show(string|array $target): ?self
    {
        if ($presenter = self::for($target)) {
            Ads::record($presenter);
        }

        return $presenter;
    }

    /**
     * Ends a bot's new scheduled post with "Presented by …" when the bot has a sponsor. A post
     * that's too long loses whole lines from the end (list items, never half a link); a
     * one-line post the line won't fit stays as it is.
     */
    public static function stamp(Dispatch $dispatch, string $bot): void
    {
        if ($dispatch->parent_id || ! in_array($bot, self::BOTS, true) || ! $presenter = self::for('bot:'.$bot)) {
            return;
        }

        $line = $presenter->line();
        $room = config('stoop.max_length') - mb_strlen($line) - 1;
        $body = rtrim((string) $dispatch->body);
        while (mb_strlen($body) > $room && str_contains($body, "\n")) {
            $body = rtrim(Str::beforeLast($body, "\n"));
        }
        if (mb_strlen($body) > $room) {
            return;
        }

        $dispatch->forceFill(['body' => $body."\n".$line, 'details' => [...$dispatch->details ?? [], 'presenter' => $presenter->id]])->save();
    }

    public function line(): string
    {
        return 'Presented by '.$this->sponsor.' '.route('ads.click', ['presenter', $this->id]);
    }

    /**
     * Everything that can carry a sponsor, as target => label, for the sales form.
     *
     * @return array<string, string>
     */
    public static function targets(): array
    {
        return collect(self::BOTS)->mapWithKeys(fn ($b) => ["bot:$b" => "@$b"])->all() + self::GAMES
            + Contest::where('closes_at', '>=', now()->subMonth())->pluck('title', 'id')->mapWithKeys(fn ($t, $id) => ["contest:$id" => "Contest: $t"])->all()
            + Hunt::where('ends_at', '>=', now())->pluck('name', 'id')->mapWithKeys(fn ($n, $id) => ["hunt:$id" => "Hunt: $n"])->all()
            + Challenge::where('ends_at', '>=', now())->pluck('title', 'id')->mapWithKeys(fn ($t, $id) => ["challenge:$id" => "Challenge: $t"])->all();
    }

    public function targetLabel(): string
    {
        [$type, $id] = array_pad(explode(':', $this->target, 2), 2, null);

        return match ($type) {
            'bot' => '@'.$id,
            'contest' => 'Contest: '.(Contest::find($id)?->title ?? 'deleted'),
            'hunt' => 'Hunt: '.(Hunt::find($id)?->name ?? 'deleted'),
            'challenge' => 'Challenge: '.(Challenge::find($id)?->title ?? 'deleted'),
            default => self::GAMES[$this->target] ?? $this->target,
        };
    }
}
