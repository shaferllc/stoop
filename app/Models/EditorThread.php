<?php

namespace App\Models;

use App\Actions\FileDispatch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Ask the editor": an editor's dispatch that readers reply to with questions during a set
 * window. Readers upvote questions; the editor's replies to a question are its answers.
 */
#[Fillable(['dispatch_id', 'user_id', 'title', 'starts_at', 'ends_at'])]
class EditorThread extends Model
{
    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function open(User $editor, string $title, Carbon $startsAt, Carbon $endsAt): self
    {
        $tz = config('stoop.timezone');

        return DB::transaction(function () use ($editor, $title, $startsAt, $endsAt, $tz) {
            $dispatch = FileDispatch::handle($editor, [
                'body' => "Ask the editor: $title. Questions open ".$startsAt->timezone($tz)->format('D M j, g:ia').' to '.$endsAt->timezone($tz)->format('D M j, g:ia').'. Ask yours in the thread and upvote the ones you want answered.',
                'line' => 'blue',
            ], confirmed: true);

            $thread = self::create(['dispatch_id' => $dispatch->id, 'user_id' => $editor->id, 'title' => $title, 'starts_at' => $startsAt, 'ends_at' => $endsAt]);
            $dispatch->forceFill(['details' => [...($dispatch->details ?? []), 'ama' => $thread->id]])->save();

            return $thread;
        });
    }

    public function isOpen(): bool
    {
        return now()->between($this->starts_at, $this->ends_at);
    }

    /**
     * Questions the viewer can see, most upvoted first, each with the editor's answers.
     *
     * @return Collection<int, Dispatch>
     */
    public function questions(?User $viewer): Collection
    {
        $questions = Dispatch::visibleTo($viewer)->with('user')->where('parent_id', $this->dispatch_id)
            ->where('user_id', '!=', $this->user_id)->whereBetween('created_at', [$this->starts_at, $this->ends_at])
            ->withCount(['replies' => fn ($q) => $q->where('status', 'approved')])
            ->addSelect(['votes' => DB::table('question_votes')->selectRaw('count(*)')->whereColumn('question_votes.dispatch_id', 'dispatches.id')])
            ->get();

        $answers = Dispatch::where('status', 'approved')->where('user_id', $this->user_id)
            ->whereIn('parent_id', $questions->pluck('id'))->oldest()->get()->groupBy('parent_id');
        $mine = $viewer ? DB::table('question_votes')->where('user_id', $viewer->id)->whereIn('dispatch_id', $questions->pluck('id'))->pluck('dispatch_id')->all() : [];

        return $questions->each(function (Dispatch $q) use ($answers, $mine) {
            $q->setRelation('answers', $answers[$q->id] ?? collect());
            $q->voted = in_array($q->id, $mine, true);
        })->sortBy([['votes', 'desc'], ['created_at', 'asc']])->values();
    }

    /**
     * Upvote a question, or take the upvote back. Only approved questions in this thread,
     * and not your own. Returns whether the viewer now has an upvote on it.
     */
    public function toggleVote(User $user, int $questionId): bool
    {
        $question = Dispatch::where('status', 'approved')->where('parent_id', $this->dispatch_id)
            ->whereBetween('created_at', [$this->starts_at, $this->ends_at])->findOrFail($questionId);
        abort_if($question->user_id === $user->id, 422, 'You can’t upvote your own question.');

        $where = ['dispatch_id' => $question->id, 'user_id' => $user->id];
        if (DB::table('question_votes')->where($where)->delete()) {
            return false;
        }
        DB::table('question_votes')->insert([...$where, 'created_at' => now()]);

        return true;
    }
}
