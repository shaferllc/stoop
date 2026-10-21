<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One clue in a hunt. The answer (stop or place) and the code word never go to players.
 */
#[Fillable(['hunt_id', 'position', 'clue', 'stop', 'place_id', 'code_word'])]
#[Hidden(['code_word', 'stop', 'place_id'])]
class HuntStep extends Model
{
    public function hunt(): BelongsTo
    {
        return $this->belongsTo(Hunt::class);
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    /**
     * Finish this step with a post at the right spot from since the hunt began. Returns why
     * it didn't count, or null when it did.
     */
    public function complete(User $user, ?string $codeWord = null): ?string
    {
        $hunt = $this->hunt;

        if (! $hunt->isRunning()) {
            return 'This hunt isn’t running right now.';
        }
        if ($this->code_word && mb_strtolower(trim((string) $codeWord)) !== mb_strtolower(trim($this->code_word))) {
            return 'That’s not the code word. Look around when you get there.';
        }

        $proof = $user->dispatches()->where('status', 'approved')->whereNull('parent_id')->whereIn('type', ['been', 'dispatch'])
            ->where('created_at', '>=', $hunt->starts_at)
            ->when($this->place_id, fn ($q) => $q->where('place_id', $this->place_id), fn ($q) => $q->where('stop', $this->stop))
            ->latest()->first();

        if (! $proof) {
            return 'We couldn’t find your post from the right spot yet. Post a Been here or a dispatch there, then check in again.';
        }

        DB::table('hunt_completions')->insertOrIgnore([
            'hunt_step_id' => $this->id, 'user_id' => $user->id, 'dispatch_id' => $proof->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return null;
    }
}
