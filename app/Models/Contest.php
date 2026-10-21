<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A guess-the-date contest, like predicting Peak Bloom. Closest guesses win; ties go
 * to whoever guessed first.
 */
#[Fillable(['slug', 'title', 'question', 'prize', 'closes_at', 'answer', 'winners'])]
class Contest extends Model
{
    protected function casts(): array
    {
        return ['closes_at' => 'datetime', 'answer' => 'date'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ContestEntry::class);
    }

    public function isOpen(): bool
    {
        return $this->closes_at->isFuture() && ! $this->answer;
    }

    /**
     * @return Collection<int, ContestEntry>
     */
    public function standings(): Collection
    {
        if (! $this->answer) {
            return collect();
        }

        return $this->entries()->with('user')->get()
            ->sortBy([fn ($a, $b) => abs($a->guess->diffInDays($this->answer)) <=> abs($b->guess->diffInDays($this->answer)), ['created_at', 'asc']])
            ->values();
    }
}
