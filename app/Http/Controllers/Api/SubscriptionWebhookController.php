<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Magazine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The magazine's subscription system tells Stoop who is subscribed.
 *
 * POST /api/subscriptions with JSON {"email": "...", "subscribed_until": "2027-10-01" or null}
 * and the header X-Stoop-Signature: sha256=<HMAC-SHA256 of the raw body with the shared secret>.
 */
class SubscriptionWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('services.subscriptions.secret');
        abort_unless($secret, 503, 'Subscription sync isn’t set up.');

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        abort_unless(hash_equals($expected, (string) $request->header('X-Stoop-Signature')), 401, 'Bad signature.');

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'subscribed_until' => ['nullable', 'date'],
        ]);

        Magazine::setSubscription($data['email'], $data['subscribed_until'] ?? null);

        return response()->json(['ok' => true]);
    }
}
