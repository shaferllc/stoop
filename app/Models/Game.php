<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A game-day thread: a poll on who wins, settled after the final whistle.
 */
#[Fillable(['team', 'opponent', 'home', 'starts_at', 'dispatch_id', 'team_option_id', 'opponent_option_id', 'winner'])]
class Game extends Model
{
    public const TEAMS = ['Nationals', 'Capitals', 'Wizards', 'Mystics', 'Commanders', 'D.C. United', 'Spirit'];

    protected function casts(): array
    {
        return ['home' => 'boolean', 'starts_at' => 'datetime'];
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class);
    }

    public function winningOptionId(): ?int
    {
        return match ($this->winner) {
            'team' => $this->team_option_id,
            'opponent' => $this->opponent_option_id,
            default => null,
        };
    }
}
