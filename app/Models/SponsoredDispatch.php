<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A paid card in feeds, labeled "Sponsored · advertiser". It is not a Dispatch: it never
 * reaches threads, bots, points, mayors, rivalries, search or the moderation queue.
 */
#[Fillable(['advertiser', 'body', 'image_path', 'url', 'stops', 'lines', 'starts_at', 'ends_at', 'daily_cap', 'goal', 'created_by'])]
class SponsoredDispatch extends Model
{
    protected function casts(): array
    {
        return ['stops' => 'array', 'lines' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    /** Running now and still short of its impression goal. */
    public function scopeRunning(Builder $query): void
    {
        $query->where('starts_at', '<=', now())->where('ends_at', '>=', now())
            ->where(fn ($q) => $q->whereNull('goal')->orWhereColumn('impressions', '<', 'goal'));
    }
}
