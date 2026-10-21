<?php

namespace App\Http\Controllers\Api;

use App\Actions\BulkPeople;
use App\Http\Controllers\Controller;
use App\Models\Dispatch;
use App\Models\Penalty;
use App\Models\SpamCluster;
use App\Models\User;
use App\Support\AccountCheck;
use App\Support\Activity;
use App\Support\BotConsole;
use App\Support\Roles;
use App\Support\SignupRisk;
use App\Support\SpamRings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * Bots, spam and operations for the Stoop Admin desktop app. Same token rules as
 * AdminController; the role each path needs is in App\Support\Roles::API.
 */
class AdminOpsController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $request, $next) {
            abort_unless($request->user()?->is_staff && $request->user()->tokenCan('admin'), 403, 'This token isn’t an admin token, or you’re no longer staff.');

            return $next($request);
        })];
    }

    // Spam rings...

    public function clusters(Request $request): JsonResponse
    {
        $status = $request->query('status', 'open');

        return response()->json(SpamCluster::when($status !== 'all', fn ($q) => $q->where('status', $status))->latest('updated_at')->take(100)->get()
            ->map(fn (SpamCluster $c) => $this->cluster($c)));
    }

    public function detect(): JsonResponse
    {
        return response()->json(['open' => SpamRings::detect()]);
    }

    public function actOnCluster(Request $request, SpamCluster $cluster): JsonResponse
    {
        $data = $request->validate(['hours' => ['integer', Rule::in(array_keys(Penalty::TIMEOUTS))]]);

        return response()->json(SpamRings::act($cluster, $request->user(), $data['hours'] ?? 24));
    }

    public function dismissCluster(SpamCluster $cluster): JsonResponse
    {
        SpamRings::dismiss($cluster);

        return response()->json(['ok' => true]);
    }

    // People...

    public function risk(User $user): JsonResponse
    {
        return response()->json([
            'score' => (int) $user->risk_score,
            'reasons' => $user->risk_reasons ?? [],
            'held' => SignupRisk::holds($user),
            'clusters' => SpamRings::for($user)->map(fn ($c) => $this->cluster($c)),
            'claude' => AccountCheck::enabled(),
        ]);
    }

    public function check(User $user): JsonResponse
    {
        return response()->json(AccountCheck::run($user));
    }

    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate(BulkPeople::rules());

        return response()->json(['count' => BulkPeople::run($data['ids'], $data['action'], $data, $request->user())]);
    }

    // House bots...

    public function answers(): JsonResponse
    {
        return response()->json(BotConsole::answers()->map(fn (Dispatch $d) => [
            'id' => $d->id,
            'bot' => $d->user->username,
            'body' => $d->body,
            'url' => route('dispatches.show', $d),
            'created_at' => $d->created_at,
            'wrong' => ! empty($d->details['marked_wrong_at']),
            'question' => $d->parent ? ['id' => $d->parent->id, 'body' => $d->parent->body, 'username' => $d->parent->user?->username] : null,
        ]));
    }

    public function markWrong(int $answer): JsonResponse
    {
        BotConsole::markWrong(BotConsole::answer($answer));

        return response()->json(['ok' => true]);
    }

    public function correct(Request $request, int $answer): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:3', 'max:'.config('stoop.max_length')]]);
        $reply = BotConsole::correct(BotConsole::answer($answer), $data['body']);

        return response()->json(['ok' => true, 'id' => $reply->id]);
    }

    public function knowledge(): JsonResponse
    {
        return response()->json(BotConsole::knowledge());
    }

    public function saveKnowledge(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => ['nullable', 'integer', 'exists:bot_knowledge,id'], ...BotConsole::entryRules()]);
        BotConsole::saveEntry($data['id'] ?? null, $data['section'], $data['body']);

        return response()->json(['ok' => true]);
    }

    public function deleteKnowledge(int $entry): JsonResponse
    {
        BotConsole::deleteEntry($entry);

        return response()->json(['ok' => true]);
    }

    // Operations...

    public function activity(): JsonResponse
    {
        return response()->json(['feed' => Activity::feed(), 'days' => Activity::days()]);
    }

    public function roles(): JsonResponse
    {
        return response()->json([
            'options' => collect(Roles::ROLES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'staff' => User::where('is_staff', true)->orderBy('username')->get()->map(fn (User $u) => [
                'id' => $u->id, 'username' => $u->username, 'name' => $u->name, 'roles' => $u->staffRoles(),
            ]),
        ]);
    }

    public function setRoles(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'exists:users,username'],
            'roles' => ['present', 'array'],
            'roles.*' => [Rule::in(array_keys(Roles::ROLES))],
        ]);
        Roles::set(User::where('username', $data['username'])->firstOrFail(), $data['roles'], $request->user());

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function cluster(SpamCluster $c): array
    {
        return [
            'id' => $c->id,
            'kind' => $c->kind,
            'kind_label' => SpamCluster::KINDS[$c->kind] ?? $c->kind,
            'summary' => $c->summary,
            'status' => $c->status,
            'members' => User::whereIn('id', $c->members)->get(['id', 'username', 'name', 'created_at'])
                ->map(fn ($u) => ['id' => $u->id, 'username' => $u->username, 'name' => $u->name, 'created_at' => $u->created_at]),
            'evidence' => $c->evidence,
            'updated_at' => $c->updated_at,
        ];
    }
}
