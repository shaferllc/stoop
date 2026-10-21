<?php

namespace App\Http\Controllers;

use App\Support\Business;
use App\Support\Stripe;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Stripe webhooks (test mode). Checked against STRIPE_WEBHOOK_SECRET, and handled once per event id.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        abort_unless(Stripe::configured(), 404);
        $event = Stripe::event($request->getContent(), $request->header('Stripe-Signature'));
        abort_unless($event, 400, 'Bad signature');

        return response(Business::handle($event) ? 'ok' : 'duplicate');
    }
}
