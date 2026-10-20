<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['path', 'alt', 'width', 'height'])]
class MessageImage extends Model
{
    public $timestamps = false;

    /**
     * Message photos live on the private disk and are served only to the conversation's members.
     */
    public function url(): string
    {
        return route('messages.image', $this);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
