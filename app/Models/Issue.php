<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A magazine issue: a cover and its stories, posted as one @magazine thread.
 */
#[Fillable(['slug', 'name', 'cover_url', 'stories'])]
class Issue extends Model
{
    protected function casts(): array
    {
        return ['stories' => 'array', 'published_at' => 'datetime'];
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class);
    }
}
