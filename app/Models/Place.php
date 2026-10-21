<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A restaurant, bar or shop people can tag in a dispatch.
 */
#[Fillable(['name', 'slug', 'category', 'neighborhood', 'stop', 'address', 'website', 'blurb', 'list_name', 'list_rank', 'article_url', 'owner_id'])]
class Place extends Model
{
    public const CATEGORIES = ['Restaurant', 'Bar', 'Coffee', 'Bakery', 'Market', 'Shop', 'Venue', 'Park', 'Other'];

    protected static function booted(): void
    {
        static::creating(function (Place $place) {
            if (! $place->slug) {
                $base = Str::slug($place->name) ?: 'place';
                $slug = $base;
                for ($i = 2; static::where('slug', $slug)->exists(); $i++) {
                    $slug = "$base-$i";
                }
                $place->slug = $slug;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(Dispatch::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(PlaceClaim::class);
    }

    public function stopName(): ?string
    {
        return $this->stop ? config("stoop.stations.{$this->stop}") : null;
    }

    public function listLabel(): ?string
    {
        return $this->list_name ? ($this->list_rank ? 'No. '.$this->list_rank.' · ' : '').$this->list_name : null;
    }
}
