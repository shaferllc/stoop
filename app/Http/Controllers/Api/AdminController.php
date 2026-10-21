<?php

namespace App\Http\Controllers\Api;

use App\Actions\Moderate;
use App\Http\Controllers\Controller;
use App\Models\Appeal;
use App\Models\Dispatch;
use App\Models\MessageReport;
use App\Models\Penalty;
use App\Models\User;
use App\Support\Attention;
use App\Support\Audit;
use App\Support\Maintenance;
use App\Support\Strikes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The staff API behind the Stoop Admin desktop app. Needs a token made with the "admin" ability
 * by someone who is still staff; losing staff access turns the token off at once.
 */
class AdminController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(function (Request $request, $next) {
            abort_unless($request->user()?->is_staff && $request->user()->tokenCan('admin'), 403, 'This token isn’t an admin token, or you’re no longer staff.');

            return $next($request);
        })];
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->person($request->user()),
            'site' => ['name' => config('app.name'), 'url' => url('/')],
            'options' => [
                'rule_reasons' => config('stoop.rule_reasons'),
                'penalties' => collect(['none' => 'No penalty', 'warning' => 'Warning'] + collect(Penalty::TIMEOUTS)->mapWithKeys(fn ($l, $h) => [(string) $h => "Timeout, $l"])->all() + ['ban' => 'Ban'])
                    ->map(fn ($label, $value) => ['value' => (string) $value, 'label' => $label])->values(),
                'lines' => collect(config('stoop.lines'))->map(fn ($l) => ['name' => $l['name'], 'color' => $l['color']]),
            ],
        ]);
    }

    public function attention(): JsonResponse
    {
        $items = Attention::items();

        return response()->json([
            'total' => array_sum(array_column($items, 'count')),
            'items' => collect($items)->map(fn ($item, $key) => ['key' => $key] + $item)->values(),
        ]);
    }

    public function stats(): JsonResponse
    {
        $day = now()->subDay();
        $week = now()->subWeek();

        return response()->json([
            'accounts' => User::where('is_bot', false)->count(),
            'new_accounts_week' => User::where('is_bot', false)->where('created_at', '>=', $week)->count(),
            'posters_day' => Dispatch::where('created_at', '>=', $day)->distinct()->count('user_id'),
            'dispatches_day' => Dispatch::where('created_at', '>=', $day)->count(),
            'weekly_posters' => Dispatch::where('created_at', '>=', $week)->distinct()->count('user_id'),
            'scheduled' => Dispatch::where('status', 'scheduled')->count(),
            'removed_week' => Dispatch::where('status', 'removed')->where('updated_at', '>=', $week)->count(),
            'penalties_week' => Penalty::where('created_at', '>=', $week)->count(),
        ]);
    }

    // Moderation...

    public function queue(): JsonResponse
    {
        return response()->json(Moderate::queue()->map(fn (Dispatch $d) => $this->dispatch($d))->values());
    }

    public function approve(Request $request, Dispatch $dispatch): JsonResponse
    {
        Moderate::approve($dispatch, $request->user());

        return response()->json(['ok' => true]);
    }

    public function remove(Request $request, Dispatch $dispatch): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(config('stoop.rule_reasons'))],
            'note' => ['nullable', 'string', 'max:200'],
            'penalty' => Moderate::penaltyRules(),
        ]);
        Moderate::remove($dispatch->load('user'), $request->user(), $data['reason'], $data['note'] ?? null, $data['penalty']);

        return response()->json(['ok' => true]);
    }

    public function appeals(Request $request): JsonResponse
    {
        return response()->json(Moderate::appeals($request->user())->map(fn (Appeal $a) => [
            'id' => $a->id,
            'body' => $a->body,
            'created_at' => $a->created_at,
            'user' => $this->person($a->user),
            'penalty' => $a->penalty ? ['type' => $a->penalty->type, 'label' => $a->penalty->label(), 'reason' => $a->penalty->reason] : null,
            'dispatch' => $a->dispatch ? $this->dispatch($a->dispatch->loadMissing('user')) : null,
        ]));
    }

    public function decide(Request $request, Appeal $appeal): JsonResponse
    {
        $data = $request->validate(['grant' => ['required', 'boolean']]);
        Moderate::decide($appeal->load(['penalty', 'dispatch', 'user']), $request->user(), $data['grant']);

        return response()->json(['ok' => true]);
    }

    public function messageReports(): JsonResponse
    {
        return response()->json(Moderate::messageReports()->map(fn (MessageReport $r) => [
            'id' => $r->id,
            'reason' => config('stoop.report_reasons.'.$r->reason, $r->reason),
            'note' => $r->note,
            'created_at' => $r->created_at,
            'reporter' => $this->person($r->user),
            'message_id' => $r->message_id,
            'context' => $r->context->map(fn ($m) => ['id' => $m->id, 'body' => $m->body, 'created_at' => $m->created_at, 'user' => $m->user ? $this->person($m->user) : null, 'reported' => $m->id === $r->message_id]),
        ]));
    }

    public function resolveMessageReport(Request $request, MessageReport $report): JsonResponse
    {
        $data = $request->validate(['timeout_hours' => ['integer', Rule::in([0, ...array_keys(Penalty::TIMEOUTS)])]]);
        Moderate::resolveMessageReport($report->load('message.user'), $request->user(), $data['timeout_hours'] ?? 0);

        return response()->json(['ok' => true]);
    }

    public function penalties(): JsonResponse
    {
        return response()->json(Penalty::with('user')->active()->whereIn('type', ['timeout', 'ban'])->latest()->get()->map(fn (Penalty $p) => $this->penalty($p)));
    }

    public function lift(Penalty $penalty): JsonResponse
    {
        Moderate::lift($penalty);

        return response()->json(['ok' => true]);
    }

    // People...

    public function users(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        $filter = $request->query('filter', 'all');
        $penalized = Penalty::active()->whereIn('type', ['timeout', 'ban'])->pluck('user_id');

        $users = User::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('username', 'like', '%'.ltrim($q, '@').'%')
                ->orWhere('name', 'like', "%$q%")
                ->orWhere('email', 'like', "%$q%")))
            ->when($filter === 'staff', fn ($query) => $query->where('is_staff', true))
            ->when($filter === 'penalized', fn ($query) => $query->whereIn('id', $penalized))
            ->when($filter === 'unverified', fn ($query) => $query->whereNull('email_verified_at'))
            ->when($filter === 'ban_review', fn ($query) => $query->whereIn('id', Strikes::banCandidates()->pluck('id')))
            ->withCount('dispatches')->latest()->paginate(50);

        return response()->json([
            'data' => $users->getCollection()->map(fn (User $u) => $this->person($u, admin: true) + ['posts' => $u->dispatches_count, 'penalized' => $penalized->contains($u->id)]),
            'next_page' => $users->hasMorePages() ? $users->currentPage() + 1 : null,
        ]);
    }

    public function user(User $user): JsonResponse
    {
        return response()->json($this->person($user, admin: true) + [
            'strikes' => Strikes::recent($user),
            'penalties' => $user->penalties()->with('user')->latest()->get()->map(fn (Penalty $p) => $this->penalty($p)),
            'dispatches' => $user->dispatches()->with('user')->withTrashed()->latest()->take(30)->get()->map(fn (Dispatch $d) => $this->dispatch($d)),
            'notes' => DB::table('user_notes')->where('user_id', $user->id)->latest()->get(['body', 'created_at']),
        ]);
    }

    public function penalize(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['warning', 'ban', ...array_map('strval', array_keys(Penalty::TIMEOUTS))])],
            'reason' => ['required', Rule::in(config('stoop.rule_reasons'))],
        ]);
        abort_if($user->is($request->user()), 422, 'You can’t penalize yourself.');

        return response()->json($this->penalty(Moderate::penalize($user, $data['type'], $data['reason'], null, $request->user())));
    }

    public function verify(User $user): JsonResponse
    {
        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();
        Audit::log('user.verified', $user);

        return response()->json(['ok' => true]);
    }

    public function note(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        DB::table('user_notes')->insert(['user_id' => $user->id, 'author_id' => $request->user()->id, 'body' => $data['body'], 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['ok' => true]);
    }

    // Keeping it running...

    public function health(): JsonResponse
    {
        $health = Maintenance::health();
        $health['tasks'] = collect($health['tasks'])->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values();

        return response()->json($health);
    }

    public function run(string $task): JsonResponse
    {
        return response()->json(['message' => Maintenance::run($task)]);
    }

    public function log(): JsonResponse
    {
        return response()->json(['log' => Maintenance::tail(storage_path('logs/laravel.log'))]);
    }

    public function failedJobs(): JsonResponse
    {
        return response()->json(DB::table('failed_jobs')->latest('failed_at')->take(50)->get()->map(fn ($job) => [
            'uuid' => $job->uuid,
            'name' => json_decode($job->payload)->displayName ?? 'Job',
            'failed_at' => $job->failed_at,
            'error' => (string) str($job->exception)->before("\n"),
        ]));
    }

    public function retryJob(string $uuid): JsonResponse
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        Audit::log('job.retried', null, ['uuid' => $uuid]);

        return response()->json(['ok' => true]);
    }

    public function forgetJob(string $uuid): JsonResponse
    {
        Artisan::call('queue:forget', ['id' => $uuid]);
        Audit::log('job.dismissed', null, ['uuid' => $uuid]);

        return response()->json(['ok' => true]);
    }

    public function audit(): JsonResponse
    {
        return response()->json(DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.actor_id')
            ->latest('audit_logs.created_at')->take(100)
            ->get(['audit_logs.id', 'audit_logs.action', 'audit_logs.subject_type', 'audit_logs.subject_id', 'audit_logs.details', 'audit_logs.created_at', 'users.username as actor'])
            ->map(fn ($row) => (array) $row));
    }

    // Shapes...

    /**
     * @return array<string, mixed>
     */
    protected function person(User $user, bool $admin = false): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'name' => $user->name,
            'url' => route('users.show', $user),
            'is_staff' => (bool) $user->is_staff,
            'is_bot' => (bool) $user->is_bot,
        ] + ($admin ? [
            'email' => $user->email,
            'verified' => (bool) $user->email_verified_at,
            'created_at' => $user->created_at,
        ] : []);
    }

    /**
     * @return array<string, mixed>
     */
    protected function dispatch(Dispatch $d): array
    {
        return [
            'id' => $d->id,
            'url' => route('dispatches.show', $d),
            'type' => $d->type,
            'line' => $d->line,
            'stop' => $d->stop,
            'stop_name' => $d->stop ? config("stoop.stations.$d->stop") : null,
            'body' => $d->body,
            'status' => $d->trashed() ? 'deleted' : $d->status,
            'flagged_at' => $d->flagged_at,
            'moderation_reason' => $d->moderation_reason,
            'screening' => $d->screening,
            'created_at' => $d->created_at,
            'user' => $this->person($d->user),
            'strikes' => $d->strikes ?? null,
            'images' => $d->images->map(fn ($i) => ['url' => $i->url(), 'alt' => $i->alt]),
            'reports' => $d->relationLoaded('reports') ? $d->reports->whereNull('resolved_at')->map(fn ($r) => [
                'reason' => config('stoop.report_reasons.'.$r->reason, $r->reason), 'note' => $r->note, 'by' => $r->user?->username,
            ])->values() : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function penalty(Penalty $p): array
    {
        return [
            'id' => $p->id,
            'type' => $p->type,
            'label' => $p->label(),
            'reason' => $p->reason,
            'ends_at' => $p->ends_at,
            'lifted_at' => $p->lifted_at,
            'created_at' => $p->created_at,
            'user' => $p->user ? $this->person($p->user) : null,
        ];
    }
}
