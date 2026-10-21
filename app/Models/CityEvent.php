<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A city event people post from (Restaurant Week, the Marine Corps Marathon, Halloween),
 * or a Stoop Sits meetup with a check-in code.
 */
#[Fillable(['slug', 'name', 'kind', 'description', 'starts_at', 'ends_at', 'rating_label', 'place_list', 'stop', 'checkin_code'])]
class CityEvent extends Model
{
    protected $table = 'events';

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(Dispatch::class, 'event_id');
    }

    public function attendees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'event_checkins', 'event_id')->withTimestamps();
    }

    public function scopeHappening(Builder $query): void
    {
        $query->where('starts_at', '<=', now())->where('ends_at', '>=', now());
    }

    public function isHappening(): bool
    {
        return $this->starts_at->isPast() && $this->ends_at->isFuture();
    }
}
