<?php

namespace App\Providers;

use App\Events\UserNotified;
use App\Models\User;
use App\Support\Live;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\DevCommands;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // `composer run dev` also starts the Reverb server and the scheduler.
        if ($this->app->runningInConsole()) {
            DevCommands::artisan('reverb:start', 'reverb');
            DevCommands::artisan('schedule:work', 'schedule');
        }

        // Every in-app notification also updates open tabs right away and, for the
        // people who turned it on, sends a push notification.
        Event::listen(function (NotificationSent $event) {
            if ($event->channel !== 'database' || ! $event->notifiable instanceof User) {
                return;
            }

            $data = $event->notification->toArray($event->notifiable);
            Live::broadcast(new UserNotified($event->notifiable->id, $data['kind'] ?? 'other'));

            $who = $data['actor'] ? '@'.$data['actor'] : 'Stoop';
            Live::push($event->notifiable, match ($data['kind'] ?? '') {
                'reply' => "$who replied",
                'mention' => "$who mentioned you",
                'quote' => "$who quoted you",
                'follow' => "$who followed you",
                default => 'Stoop',
            }, $data['message'] ?? $data['excerpt'] ?? '', $data['dispatch_id'] ?? null ? route('dispatches.show', $data['dispatch_id']) : route('notifications'));
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
