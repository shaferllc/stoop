<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A self-serve boost of a deal, happening or dispatch: unpaid, then review (paid or the free
 * monthly one), then approved (running as a promoted ad) or rejected (refunded).
 */
#[Fillable(['user_id', 'place_id', 'kind', 'target_id', 'name', 'stops', 'lines', 'starts_on', 'ends_on', 'tier', 'amount_cents', 'impressions', 'free', 'status', 'stripe_session_id', 'payment_intent', 'sponsored_dispatch_id', 'reviewed_by', 'reviewed_at', 'reject_reason', 'refunded_at'])]
class Promotion extends Model
{
    protected function casts(): array
    {
        return ['stops' => 'array', 'lines' => 'array', 'starts_on' => 'date', 'ends_on' => 'date', 'free' => 'boolean', 'reviewed_at' => 'datetime', 'refunded_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    public function ad(): BelongsTo
    {
        return $this->belongsTo(SponsoredDispatch::class, 'sponsored_dispatch_id');
    }

    /**
     * The deal or dispatch being promoted.
     */
    public function target(): PlaceDeal|Dispatch|null
    {
        return $this->kind === 'deal' ? PlaceDeal::find($this->target_id) : Dispatch::find($this->target_id);
    }
}
