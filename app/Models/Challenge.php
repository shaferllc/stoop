<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A challenge on one of the magazine's lists: "Eat at 10 of the 100 Very Best" between two
 * dates. Prizes are sent by hand from the finishers list.
 */
#[Fillable(['slug', 'title', 'list_name', 'goal', 'starts_at', 'ends_at', 'prize', 'badge_name', 'created_by'])]
class Challenge extends Model
{
    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'goal' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function scopeRunning(Builder $query): void
    {
        $query->where('starts_at', '<=', now())->where('ends_at', '>=', now());
    }

    public function isRunning(): bool
    {
        return $this->starts_at->isPast() && $this->ends_at->isFuture();
    }
}
