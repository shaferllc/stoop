<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A paid placement on a station or line feed. It's always labeled "Sponsored".
 */
#[Fillable(['sponsor', 'place_id', 'stop', 'line', 'headline', 'body', 'url', 'starts_on', 'ends_on'])]
class Sponsorship extends Model
{
    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    public function scopeRunning(Builder $query): void
    {
        $today = now(config('stoop.timezone'))->toDateString();
        $query->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>=', $today);
    }

    /**
     * The sponsorship to show on a feed for a stop or line, if any.
     */
    public static function for(?string $stop, ?string $line): ?self
    {
        if (! $stop && ! $line) {
            return null;
        }

        return static::running()
            ->where(fn ($q) => $q->when($stop, fn ($q) => $q->where('stop', $stop))->when($line, fn ($q) => $q->orWhere('line', $line)))
            ->inRandomOrder()->first();
    }
}
