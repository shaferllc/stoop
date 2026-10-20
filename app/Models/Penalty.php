<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'subject_hash', 'type', 'reason', 'dispatch_id', 'moderator_id', 'ends_at', 'lifted_at'])]
class Penalty extends Model
{
    public const TIMEOUTS = [1 => '1 hour', 24 => '1 day', 72 => '3 days', 168 => '7 days', 720 => '30 days'];

    protected function casts(): array
    {
        return ['ends_at' => 'datetime', 'lifted_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class)->withTrashed();
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('lifted_at')->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function label(): string
    {
        return match ($this->type) {
            'warning' => 'Warning',
            'ban' => 'Ban',
            default => 'Timeout until '.$this->ends_at?->format('M j, g:ia'),
        };
    }
}
