<?php

namespace App\Models;

use Database\Factories\DispatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A post on Stoop. Dispatches are never edited, only created and deleted.
 */
#[Fillable(['user_id', 'parent_id', 'quote_id', 'type', 'line', 'stop', 'body', 'happening_at', 'status'])]
class Dispatch extends Model
{
    /** @use HasFactory<DispatchFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function quoted(): BelongsTo
    {
        return $this->belongsTo(self::class, 'quote_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(self::class, 'quote_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(PollOption::class);
    }

    public function voters(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'poll_votes')->withPivot('poll_option_id')->withTimestamps();
    }

    public function markers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'marks')->withPivot('kind')->withTimestamps();
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function scopeTopLevel(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /**
     * Approved dispatches, plus the viewer's own, minus anything the viewer reported
     * and anyone the viewer muted or blocked (or who blocked the viewer).
     */
    public function scopeVisibleTo(Builder $query, ?User $viewer): void
    {
        $query->where(fn (Builder $q) => $q
            ->where($this->qualifyColumn('status'), 'approved')
            ->when($viewer, fn (Builder $q) => $q->orWhere($this->qualifyColumn('user_id'), $viewer->id)));

        if ($viewer) {
            $query->whereNotIn($this->qualifyColumn('user_id'), $viewer->hiddenUserIds())
                ->whereNotIn($this->qualifyColumn('id'), Report::select('dispatch_id')->where('user_id', $viewer->id));
        }
    }

    /**
     * Counts and relations every dispatch card needs.
     */
    public function scopeForCards(Builder $query): void
    {
        $query->with(['user', 'quoted.user', 'options' => fn ($q) => $q->withCount('voters')])
            ->withCount([
                'replies' => fn ($q) => $q->where('status', 'approved'),
                'quotes' => fn ($q) => $q->where('status', 'approved'),
                'markers as been_count' => fn ($q) => $q->where('marks.kind', 'been'),
                'markers as going_count' => fn ($q) => $q->where('marks.kind', 'going'),
            ]);
    }

    /**
     * Rush Hour ranking: engagement weighed against age, so a post needs twice the
     * engagement to keep up with one posted three hours later (same curve as /dev/null).
     */
    public function rushScore(): float
    {
        $engagement = 3 * ($this->replies_count + $this->quotes_count)
            + $this->been_count + $this->going_count
            + $this->options->sum('voters_count') / 4;

        return ($engagement + 1) / 2 ** ($this->created_at->diffInMinutes(now()) / 180);
    }

    public function lineName(): string
    {
        return config("stoop.lines.{$this->line}.name");
    }

    public function lineColor(): string
    {
        return config("stoop.lines.{$this->line}.color");
    }

    public function stopName(): ?string
    {
        return $this->stop ? config("stoop.stations.{$this->stop}") : null;
    }
}
