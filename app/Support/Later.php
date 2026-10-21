<?php

namespace App\Support;

/**
 * Runs a job that calls an outside service (Claude) without making the person wait.
 * With a real queue it's queued as normal. With the sync queue (local development) it
 * runs after the response is sent, and a failure is swallowed instead of showing up
 * as an error on a post that was saved fine.
 */
class Later
{
    public static function run(object $job): void
    {
        if (config('queue.default') !== 'sync') {
            dispatch($job);

            return;
        }

        $run = fn () => rescue(fn () => $job->handle(), null, false);

        app()->runningUnitTests() ? $run() : app()->terminating($run);
    }
}
