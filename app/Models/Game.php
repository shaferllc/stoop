<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A game-day thread: a poll on who wins, settled after the final whistle. Games for the
 * teams in config/sports.php are imported from ESPN with live scores (App\Support\Sports);
 * staff can still add one by hand in Admin → Moments.
 */
#[Fillable(['team', 'opponent', 'home', 'starts_at', 'dispatch_id', 'team_option_id', 'opponent_option_id', 'winner',
    'team_key', 'league', 'season', 'external_id', 'status', 'status_detail', 'team_score', 'opponent_score', 'venue', 'stop', 'final_posted_at'])]
class Game extends Model
{
    public const TEAMS = ['Nationals', 'Capitals', 'Wizards', 'Mystics', 'Commanders', 'D.C. United', 'Spirit'];

    protected function casts(): array
    {
        return ['home' => 'boolean', 'starts_at' => 'datetime', 'final_posted_at' => 'datetime'];
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class);
    }

    /** Happenings hosting a watch party for this game. */
    public function watchParties(): BelongsToMany
    {
        return $this->belongsToMany(Dispatch::class, 'watch_parties')->withTimestamps();
    }

    public function winningOptionId(): ?int
    {
        return match ($this->winner) {
            'team' => $this->team_option_id,
            'opponent' => $this->opponent_option_id,
            default => null,
        };
    }

    /** @return array<string, mixed>|null */
    public function teamConfig(): ?array
    {
        return $this->team_key ? config('sports.teams.'.$this->team_key) : null;
    }

    public function isLive(): bool
    {
        return $this->status === 'live';
    }

    public function matchup(): string
    {
        return $this->team.' '.($this->home ? 'vs.' : 'at').' '.$this->opponent;
    }

    /** "Capitals 5, Hurricanes 2", or null before there's a score. */
    public function score(): ?string
    {
        return $this->team_score === null ? null : $this->team.' '.$this->team_score.', '.$this->opponent.' '.$this->opponent_score;
    }

    public function when(): string
    {
        $at = $this->starts_at->timezone(config('stoop.timezone'));

        return $this->status_detail === 'TBA' ? $at->format('D M j').', time TBA' : $at->format('D M j, g:ia');
    }
}
