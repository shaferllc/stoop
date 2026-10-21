<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The host venue that sponsors a Stoop Sits meetup, with an optional offer for people who
 * check in. Always labeled "Sponsored".
 */
#[Fillable(['event_id', 'place_id', 'offer', 'url'])]
class MeetupSponsor extends Model
{
    public function event(): BelongsTo
    {
        return $this->belongsTo(CityEvent::class, 'event_id');
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(MeetupRedemption::class);
    }

    /** The name the ads reports show. */
    public function getSponsorAttribute(): ?string
    {
        return $this->place?->name;
    }
}
