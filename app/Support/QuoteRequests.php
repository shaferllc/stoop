<?php

namespace App\Support;

use App\Models\Dispatch;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Notifications\MagazineActivity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Editors ask before quoting a reader's dispatch in a story. The author allows or declines
 * from their notifications; every step is kept in quote_consents for legal.
 */
class QuoteRequests
{
    /**
     * Ask the author. Returns why it can't be asked, or null when the request went out.
     */
    public static function request(User $editor, Dispatch $dispatch): ?string
    {
        abort_unless($editor->is_staff, 403);

        if ($dispatch->status !== 'approved' || ! $dispatch->user || $dispatch->user->isHouseAccount() || $dispatch->user_id === $editor->id) {
            return 'This dispatch can’t be quoted.';
        }

        $open = QuoteRequest::where('dispatch_id', $dispatch->id)
            ->where(fn ($q) => $q->where('status', 'allowed')->orWhere(fn ($q) => $q->where('status', 'pending')->where('expires_at', '>', now())))
            ->exists();
        if ($open) {
            return 'There’s already a request out for this dispatch.';
        }

        $request = QuoteRequest::create([
            'dispatch_id' => $dispatch->id,
            'editor_id' => $editor->id,
            'status' => 'pending',
            'expires_at' => now()->addDays(QuoteRequest::DAYS),
        ]);

        self::log($request, $editor, 'requested');
        Audit::log('quote.requested', $dispatch, ['quote_request_id' => $request->id]);
        $dispatch->user->notify(new MagazineActivity('quote_request', $editor, $dispatch, ['quote_request_id' => $request->id]));

        return null;
    }

    /**
     * The author's answer. Declining also withdraws an earlier yes, which takes the quote card down.
     */
    public static function decide(User $author, QuoteRequest $request, bool $allow): void
    {
        abort_unless($request->dispatch?->user_id === $author->id, 403);
        abort_if($allow && $request->state() !== 'pending', 422, 'This request has expired or was already answered.');
        abort_if(! $allow && ! in_array($request->state(), ['pending', 'allowed'], true), 422, 'This request was already answered.');

        $revoking = $request->status === 'allowed';
        $request->forceFill(['status' => $allow ? 'allowed' : 'declined', 'decided_at' => now()])->save();
        self::log($request, $author, $allow ? 'allowed' : ($revoking ? 'revoked' : 'declined'));
        Cache::forget("embed:quote:{$request->id}");
    }

    /**
     * The quote card for an embed, when it's allowed and the dispatch is still up.
     */
    public static function card(int $id): ?QuoteRequest
    {
        $request = QuoteRequest::with('dispatch.user')->where('status', 'allowed')->find($id);

        return $request?->dispatch?->status === 'approved' && $request->dispatch->user ? $request : null;
    }

    protected static function log(QuoteRequest $request, User $user, string $action): void
    {
        DB::table('quote_consents')->insert([
            'quote_request_id' => $request->id,
            'dispatch_id' => $request->dispatch_id,
            'user_id' => $user->id,
            'username' => $user->username,
            'action' => $action,
            'body_hash' => hash('sha256', (string) $request->dispatch?->body),
            'created_at' => now(),
        ]);
    }
}
