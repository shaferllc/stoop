<?php

namespace App\Http\Controllers;

use App\Models\Dispatch;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Everything Stoop stores about you, as one JSON file.
 */
class ExportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $data = [
            'exported_at' => now()->toIso8601String(),
            'profile' => $user->only(['name', 'username', 'email', 'is_native', 'dc_since', 'stops', 'replies_from', 'dms_on', 'message_requests', 'read_receipts', 'half_smokes_on', 'translation_language', 'newsletter', 'subscribed_until', 'created_at']),
            'dispatches' => Dispatch::withTrashed()->where('user_id', $user->id)->with(['images', 'options'])->get()
                ->map(fn ($d) => $d->only(['id', 'parent_id', 'quote_id', 'type', 'line', 'stop', 'body', 'happening_at', 'safety', 'status', 'created_at', 'deleted_at'])
                    + ['photos' => $d->images->map->only(['path', 'alt']), 'poll_options' => $d->options->pluck('label')]),
            'following' => $user->following()->pluck('username'),
            'followers' => $user->followers()->pluck('username'),
            'muted_and_blocked' => $user->silenced()->get()->map(fn ($u) => ['username' => $u->username, 'type' => $u->pivot->type]),
            'marks' => DB::table('marks')->where('user_id', $user->id)->get(['dispatch_id', 'kind', 'created_at']),
            'poll_votes' => DB::table('poll_votes')->where('user_id', $user->id)->get(['dispatch_id', 'poll_option_id', 'created_at']),
            'half_smokes_given' => DB::table('half_smokes')->where('giver_id', $user->id)->get(['dispatch_id', 'created_at']),
            'reports_filed' => DB::table('reports')->where('user_id', $user->id)->get(['dispatch_id', 'reason', 'note', 'created_at']),
            'messages_sent' => Message::where('user_id', $user->id)->get()->map->only(['conversation_id', 'body', 'created_at']),
            'notifications' => $user->notifications()->get(['data', 'created_at', 'read_at']),
            'penalties' => $user->penalties()->get(['type', 'reason', 'ends_at', 'lifted_at', 'created_at']),
            'place_claims' => DB::table('place_claims')->join('places', 'places.id', '=', 'place_claims.place_id')->where('place_claims.user_id', $user->id)->get(['places.name', 'place_claims.role', 'place_claims.evidence', 'place_claims.status', 'place_claims.created_at']),
            'places_you_manage' => DB::table('places')->where('owner_id', $user->id)->pluck('name'),
            'push_devices' => DB::table('push_subscriptions')->where('user_id', $user->id)->get(['endpoint', 'created_at']),
            'notes_you_wrote_as_staff' => DB::table('user_notes')->where('author_id', $user->id)->get(['user_id', 'body', 'created_at']),
            'bingo_marks' => DB::table('bingo_marks')->where('user_id', $user->id)->get(['week', 'square', 'dispatch_id', 'created_at']),
            'hunt_stamps' => DB::table('hunt_completions')->join('hunt_steps', 'hunt_steps.id', '=', 'hunt_completions.hunt_step_id')->join('hunts', 'hunts.id', '=', 'hunt_steps.hunt_id')
                ->where('hunt_completions.user_id', $user->id)->get(['hunts.name as hunt', 'hunt_steps.position', 'hunt_completions.dispatch_id', 'hunt_completions.created_at']),
            'place_deals' => DB::table('place_deals')->where('user_id', $user->id)->get(['place_id', 'title', 'details', 'starts_at', 'ends_at', 'created_at']),
            'favorite_teams' => DB::table('team_fans')->where('user_id', $user->id)->pluck('team_key'),
            'api_tokens' => $user->tokens()->get(['name', 'last_used_at', 'created_at']),
        ];

        return response()->streamDownload(
            fn () => print (json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'stoop-'.$user->username.'-'.now()->format('Y-m-d').'.json',
            ['Content-Type' => 'application/json'],
        );
    }
}
