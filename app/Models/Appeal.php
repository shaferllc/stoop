<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'penalty_id', 'dispatch_id', 'body', 'status', 'decided_by', 'decided_at'])]
class Appeal extends Model
{
    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function penalty(): BelongsTo
    {
        return $this->belongsTo(Penalty::class);
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class)->withTrashed();
    }

    /**
     * The moderator whose decision is being appealed. They can't decide the appeal.
     */
    public function originalModeratorId(): ?int
    {
        return $this->penalty?->moderator_id ?? $this->dispatch?->moderated_by;
    }
}
