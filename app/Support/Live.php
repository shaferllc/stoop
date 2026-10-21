<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Real-time updates (Reverb) and push notifications. Both are best effort: if the
 * Reverb server isn't running or a push service is down, the action that triggered
 * them still succeeds, and pages fall back to checking every so often.
 */
class Live
{
    public static function broadcast(object $event): void
    {
        try {
            broadcast($event);
        } catch (Throwable $e) {
            Log::debug('Broadcast skipped', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Send a push notification to every device a person turned notifications on for.
     */
    public static function push(User $user, string $title, string $body, string $url): void
    {
        $subscriptions = DB::table('push_subscriptions')->where('user_id', $user->id)->get();

        if ($subscriptions->isEmpty() || ! config('services.webpush.public_key')) {
            return;
        }

        try {
            $webPush = new WebPush(['VAPID' => [
                'subject' => config('app.url'),
                'publicKey' => config('services.webpush.public_key'),
                'privateKey' => config('services.webpush.private_key'),
            ]], ['TTL' => 3600, 'urgency' => 'normal'], 5);

            $payload = json_encode(['title' => $title, 'body' => str($body)->limit(140)->toString(), 'url' => $url]);

            foreach ($subscriptions as $s) {
                $webPush->queueNotification(Subscription::create([
                    'endpoint' => $s->endpoint, 'publicKey' => $s->public_key, 'authToken' => $s->auth_token,
                ]), $payload);
            }

            foreach ($webPush->flush() as $report) {
                // The browser unsubscribed or the app was removed: forget that device.
                if ($report->isSubscriptionExpired()) {
                    DB::table('push_subscriptions')->where('endpoint', $report->getEndpoint())->delete();
                }
            }
        } catch (Throwable $e) {
            Log::warning('Push failed', ['error' => $e->getMessage()]);
        }
    }
}
