<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A group of accounts that look like they're working together, found by App\Support\SpamRings.
 */
#[Fillable(['kind', 'signature', 'summary', 'members', 'evidence', 'status'])]
class SpamCluster extends Model
{
    public const KINDS = [
        'duplicate' => 'Near-identical posts',
        'thread_burst' => 'New accounts piling into a thread',
        'link' => 'Same link from new accounts',
        'dm_blast' => 'New accounts messaging many people',
    ];

    protected function casts(): array
    {
        return ['members' => 'array', 'evidence' => 'array', 'actioned_at' => 'datetime'];
    }
}
