<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['label'])]
class PollOption extends Model
{
    public $timestamps = false;

    public function voters(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'poll_votes')->withTimestamps();
    }
}
