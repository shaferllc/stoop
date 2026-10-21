<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Records a staff action in the audit log.
 */
class Audit
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function log(string $action, ?Model $subject = null, array $details = []): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => Auth::id(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'details' => $details ? json_encode($details) : null,
            'created_at' => now(),
        ]);
    }
}
