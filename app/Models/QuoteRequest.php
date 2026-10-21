<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An editor asking a dispatch's author for permission to quote it in a story.
 */
#[Fillable(['dispatch_id', 'editor_id', 'status', 'expires_at', 'decided_at'])]
class QuoteRequest extends Model
{
    public const DAYS = 14;

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editor_id');
    }

    /**
     * pending, allowed, declined or expired (pending past its 14 days).
     */
    public function state(): string
    {
        return $this->status === 'pending' && $this->expires_at->isPast() ? 'expired' : $this->status;
    }
}
