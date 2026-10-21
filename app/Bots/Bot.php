<?php

namespace App\Bots;

use App\Actions\FileDispatch;
use App\Models\Dispatch;
use App\Models\User;
use App\Support\Bots;
use App\Support\Mentions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;

/**
 * A house bot. Each bot is one class in app/Bots; Stoop finds them on its own.
 *
 * Bots are labeled as automated, can't moderate, and reply at most once per dispatch.
 */
abstract class Bot
{
    /** The bot's @username, which is also its account. */
    abstract public function username(): string;

    abstract public function name(): string;

    /** One line for its profile and the bot console. */
    abstract public function description(): string;

    /**
     * A reply to a newly public dispatch, or null to stay quiet. By default bots answer when mentioned.
     */
    public function reply(Dispatch $dispatch): ?string
    {
        return null;
    }

    public function wantsToReply(Dispatch $dispatch): bool
    {
        return in_array($this->username(), Mentions::usernames($dispatch->body), true);
    }

    /** Register scheduled posts, e.g. $schedule->call(fn () => $this->post([...]))->dailyAt('7:00'). */
    public function schedule(Schedule $schedule): void {}

    /**
     * Post as this bot. Goes through the same rules as everyone else.
     *
     * @param  array<string, mixed>  $data  body, line, stop, type, …
     */
    public function post(array $data): Dispatch
    {
        return FileDispatch::handle($this->account(), $data, confirmed: true);
    }

    public function account(): User
    {
        return Bots::account($this->username(), $this->name());
    }

    /**
     * @return list<Bot>
     */
    public static function all(): array
    {
        return once(fn () => collect(File::glob(app_path('Bots/*.php')))
            ->map(fn ($file) => 'App\\Bots\\'.basename($file, '.php'))
            ->filter(fn ($class) => is_subclass_of($class, self::class) && ! (new \ReflectionClass($class))->isAbstract())
            ->map(fn ($class) => app($class))
            ->values()->all());
    }

    public static function find(string $username): ?self
    {
        return collect(self::all())->first(fn (Bot $bot) => $bot->username() === $username);
    }
}
