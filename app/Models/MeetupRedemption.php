<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A checked-in attendee's code for a meetup sponsor's offer. The venue owner marks it once.
 */
#[Fillable(['meetup_sponsor_id', 'user_id', 'code', 'redeemed_at'])]
class MeetupRedemption extends Model
{
    protected function casts(): array
    {
        return ['redeemed_at' => 'datetime'];
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(MeetupSponsor::class, 'meetup_sponsor_id');
    }
}
