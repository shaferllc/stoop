<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A seasonal scavenger hunt: clues that lead to stations or places. Players finish a step by
 * posting a Been here or dispatch at the right spot (plus a code word, if the clue has one),
 * and every step is a passport stamp.
 */
#[Fillable(['slug', 'name', 'description', 'starts_at', 'ends_at', 'created_by'])]
class Hunt extends Model
{
    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function steps(): HasMany
    {
        return $this->hasMany(HuntStep::class)->orderBy('position');
    }

    public function scopeRunning(Builder $query): void
    {
        $query->where('starts_at', '<=', now())->where('ends_at', '>=', now());
    }

    public function isRunning(): bool
    {
        return $this->starts_at->isPast() && $this->ends_at->isFuture();
    }

    /**
     * Players by steps found, ties going to whoever finished their last step first.
     *
     * @return Collection<int, object{user: User, steps: int, last_at: string}>
     */
    public function leaderboard(int $limit = 25): Collection
    {
        $rows = DB::table('hunt_completions')->join('hunt_steps', 'hunt_steps.id', '=', 'hunt_completions.hunt_step_id')
            ->where('hunt_steps.hunt_id', $this->id)->groupBy('hunt_completions.user_id')
            ->selectRaw('hunt_completions.user_id, count(*) as steps, max(hunt_completions.created_at) as last_at')
            ->orderByDesc('steps')->orderBy('last_at')->take($limit)->get();
        $users = User::whereIn('id', $rows->pluck('user_id'))->get(['id', 'name', 'username'])->keyBy('id');

        return $rows->filter(fn ($r) => isset($users[$r->user_id]))
            ->map(fn ($r) => (object) ['user' => $users[$r->user_id], 'steps' => (int) $r->steps, 'last_at' => $r->last_at])->values();
    }
}
