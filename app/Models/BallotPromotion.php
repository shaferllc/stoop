<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The magazine's Best of Washington party, promoted on its ballot and in feeds while the
 * ballot is open or until the party. Tickets are an outside link; Stoop only counts clicks.
 */
#[Fillable(['ballot_id', 'title', 'event_on', 'venue', 'url', 'price'])]
class BallotPromotion extends Model
{
    protected function casts(): array
    {
        return ['event_on' => 'date'];
    }

    public function ballot(): BelongsTo
    {
        return $this->belongsTo(Ballot::class);
    }

    /** From the ballot opening until it closes or the party has happened, whichever is later. */
    public function scopeRunning(Builder $query): void
    {
        $query->whereHas('ballot', fn ($q) => $q->where('opens_at', '<=', now()))
            ->where(fn ($q) => $q->whereDate('event_on', '>=', now(config('stoop.timezone'))->toDateString())
                ->orWhereHas('ballot', fn ($q) => $q->where('closes_at', '>=', now())));
    }
}
