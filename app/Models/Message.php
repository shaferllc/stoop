<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A private message. The body is encrypted at rest with the app key (not end-to-end).
 * Messages can't be edited or deleted.
 */
#[Fillable(['conversation_id', 'user_id', 'reply_to_id', 'body'])]
class Message extends Model
{
    public const MAX_LENGTH = 2000;

    protected function casts(): array
    {
        return ['body' => 'encrypted'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(MessageImage::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }
}
