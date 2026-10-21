<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A classified listing (see App\Support\Classifieds).
 */
#[Fillable(['user_id', 'category', 'title', 'body', 'stop', 'price', 'pay_min', 'pay_max', 'pay_period', 'status', 'screening', 'expires_at', 'checkout_session', 'paid_cents'])]
class Listing extends Model
{
    protected function casts(): array
    {
        return ['screening' => 'array', 'expires_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ListingReport::class);
    }

    /**
     * Listings anyone can see: active and not expired.
     *
     * @param  Builder<Listing>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', 'active')->where('expires_at', '>', now());
    }

    public function isLive(): bool
    {
        return $this->status === 'active' && $this->expires_at?->isFuture();
    }

    public function categoryName(): string
    {
        return config("classifieds.categories.{$this->category}.name", ucfirst($this->category));
    }

    public function stopName(): ?string
    {
        return $this->stop ? config("stoop.stations.{$this->stop}") : null;
    }

    /**
     * "$1,200", "$22–$28 an hour", or null.
     */
    public function priceLabel(): ?string
    {
        if ($this->pay_min) {
            return '$'.number_format($this->pay_min).'–$'.number_format($this->pay_max).' '.config("classifieds.pay_periods.{$this->pay_period}", '');
        }

        return $this->price === null ? null : ($this->price === 0 ? 'Free' : '$'.number_format($this->price));
    }

    /**
     * What the poster sees about their own listing.
     */
    public function statusLabel(): string
    {
        return match (true) {
            $this->status === 'active' && ! $this->isLive() => 'Expired',
            default => ['unpaid' => 'Waiting for payment', 'active' => 'Live', 'held' => 'Waiting for a moderator', 'removed' => 'Removed', 'filled' => $this->category === 'forsale' ? 'Sold' : 'Filled'][$this->status] ?? $this->status,
        };
    }
}
