<?php

namespace App\Jobs;

use App\Actions\AnnounceDispatch;
use App\Models\Dispatch;
use App\Support\Later;
use App\Support\ModerationModel;
use App\Support\OpenAiModeration;
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

        $screening = [];

        // 1. OpenAI's free check: hate, harassment, threats, and photos.
        if (OpenAiModeration::enabled()) {
            $result = OpenAiModeration::check($dispatch);
            $screening['openai'] = $result['flagged'];

            if ($result['hold']) {
                $dispatch->forceFill(['status' => 'held', 'screening' => $screening])->save();

                return;
            }
        }

        // 2. Claude, for Stoop's own rules: profiling, campaign posts, pitches, coded attacks.
        if (ModerationModel::enabled()) {
            $verdict = ModerationModel::tryAssess($dispatch->body);

            if ($verdict === null) {
                throw new RuntimeException('Moderation check failed; will retry.');
            }

            $screening['claude'] = $verdict;
        }

        $verdict = ($screening['claude'] ?? 'allow');
        $dispatch->forceFill(['status' => $verdict === 'allow' ? 'approved' : 'held', 'screening' => $screening])->save();

        if ($verdict === 'allow') {
            AnnounceDispatch::run($dispatch);
            Later::run(new RunBots($dispatch));
        }
    }
}
