<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Stripe\ApiRequestor;
use Stripe\Exception\SignatureVerificationException;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;
use Stripe\Util\CaseInsensitiveArray;
use Stripe\Util\Util;
use Stripe\WebhookSignature;

/**
 * Stripe in test mode only. stripe-php sends its requests through Laravel's Http client, so
 * they get a timeout and tests can fake api.stripe.com with Http::fake.
 */
class Stripe
{
    /**
     * A test-mode secret key is set. Live keys are refused so nothing real is ever charged.
     */
    public static function configured(): bool
    {
        return (bool) preg_match('/^(sk|rk)_test_/', (string) config('business.stripe.secret'));
    }

    public static function client(): StripeClient
    {
        abort_unless(self::configured(), 503, 'Stripe not configured');
        ApiRequestor::setHttpClient(new class implements ClientInterface
        {
            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $named = collect($headers)->mapWithKeys(fn ($h) => [trim(strstr($h, ':', true)) => trim(substr(strstr($h, ':'), 1))])->all();
                $http = Http::withHeaders($named)->timeout(20);
                $response = $method === 'post'
                    ? $http->withBody(Util::encodeParameters(Util::objectsToIds($params, false)), 'application/x-www-form-urlencoded')->post($absUrl)
                    : $http->send(strtoupper($method), $absUrl, ['query' => Util::objectsToIds($params, false)]);

                return [$response->body(), $response->status(), new CaseInsensitiveArray(array_map(fn ($v) => implode(', ', $v), $response->headers()))];
            }
        });

        return new StripeClient(['api_key' => config('business.stripe.secret'), 'max_network_retries' => 0]);
    }

    /**
     * The event from a webhook body, or null when the signature doesn't check out.
     *
     * @return array<string, mixed>|null
     */
    public static function event(string $payload, ?string $signature): ?array
    {
        $secret = (string) config('business.stripe.webhook_secret');
        if ($secret === '' || ! $signature) {
            return null;
        }
        try {
            WebhookSignature::verifyHeader($payload, $signature, $secret, 300);
        } catch (SignatureVerificationException) {
            return null;
        }

        $event = json_decode($payload, true);

        return is_array($event) && isset($event['id'], $event['type']) ? $event : null;
    }
}
