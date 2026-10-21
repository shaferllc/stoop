<?php

namespace App\Actions;

use App\Models\Penalty;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * One action on many people from the People list: verify, timeout or add a note.
 * Staff, house accounts and the person acting are never put in a timeout.
 */
class BulkPeople
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'max:200'],
            'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['verify', 'timeout', 'note'])],
            'hours' => ['required_if:action,timeout', 'nullable', 'integer', Rule::in(array_keys(Penalty::TIMEOUTS))],
            'reason' => ['required_if:action,timeout', 'nullable', Rule::in(config('stoop.rule_reasons'))],
            'body' => ['required_if:action,note', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @param  array<int, int>  $ids
     * @param  array<string, mixed>  $data
     * @return int How many people it applied to
     */
    public static function run(array $ids, string $action, array $data, User $staff): int
    {
        $users = User::whereIn('id', $ids)->get();

        if ($action === 'timeout') {
            $users = $users->reject(fn (User $u) => $u->is_staff || $u->is_bot || $u->is($staff));
            $users->each(fn (User $u) => Moderate::penalize($u, (string) $data['hours'], $data['reason'], null, $staff));
        } elseif ($action === 'verify') {
            $users = $users->whereNull('email_verified_at');
            $users->each(fn (User $u) => $u->forceFill(['email_verified_at' => now()])->save());
        } else {
            DB::table('user_notes')->insert($users->map(fn (User $u) => ['user_id' => $u->id, 'author_id' => $staff->id, 'body' => $data['body'], 'created_at' => now(), 'updated_at' => now()])->all());
        }

        Audit::log('users.bulk_'.$action, null, ['ids' => $users->pluck('id')->values()->all()]);

        return $users->count();
    }
}
