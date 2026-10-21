<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reader's tip to the newsroom. Private: only staff see it, and it's never posted.
 */
#[Fillable(['user_id', 'body', 'link', 'dispatch_id', 'contact_ok', 'photos', 'screening', 'status', 'assigned_to'])]
class Tip extends Model
{
    public const STATUSES = ['new' => 'New', 'following' => 'Following up', 'used' => 'Used', 'dismissed' => 'Dismissed'];

    protected function casts(): array
    {
        return ['contact_ok' => 'boolean', 'photos' => 'array', 'screening' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class)->withTrashed();
    }
}
