<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A private conversation between two people, or a group chat of up to 100.
 */
#[Fillable(['is_group', 'name', 'created_by', 'last_message_at'])]
class Conversation extends Model
{
    public const MAX_MEMBERS = 100;

    protected function casts(): array
    {
        return ['is_group' => 'boolean', 'last_message_at' => 'datetime'];
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
            ->withPivot(['role', 'accepted_at', 'last_read_at', 'archived_at'])
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function pivotFor(User $user): ?object
    {
        return $this->participants->firstWhere('id', $user->id)?->pivot;
    }

    public function isAdmin(User $user): bool
    {
        return $this->pivotFor($user)?->role === 'admin';
    }

    /**
     * The group name, or the other person's name in a one-to-one conversation.
     */
    public function titleFor(User $viewer): string
    {
        if ($this->is_group) {
            return $this->name ?: $this->participants->where('id', '!=', $viewer->id)->pluck('name')->take(3)->join(', ');
        }

        return $this->participants->firstWhere('id', '!=', $viewer->id)?->name ?? 'Deleted account';
    }
}
