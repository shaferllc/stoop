<?php

namespace App\Jobs;

use App\Actions\AnnounceDispatch;
use App\Models\Dispatch;
use App\Support\Later;
use App\Support\ModerationModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Runs the moderation model on a pending dispatch, then publishes it or holds it for people.
 * If the check keeps failing, the dispatch stays pending and shows up in the moderation queue.
 */
class ModerateDispatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 20;

    public function __construct(public Dispatch $dispatch) {}

    public function handle(): void
    {
        $dispatch = $this->dispatch->fresh();

        if (! $dispatch || $dispatch->status !== 'pending') {
            return;
        }

        $verdict = ModerationModel::tryAssess($dispatch->body);

        if ($verdict === null) {
            throw new RuntimeException('Moderation check failed; will retry.');
        }

        $dispatch->forceFill(['status' => $verdict === 'allow' ? 'approved' : 'held'])->save();

        if ($verdict === 'allow') {
            AnnounceDispatch::run($dispatch);
            Later::run(new RunBots($dispatch));
        }
    }
}
