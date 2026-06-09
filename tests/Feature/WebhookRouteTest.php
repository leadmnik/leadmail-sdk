<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use LeadM\LeadMail\Events\LeadMailWebhookReceived;

const ROUTE_SECRET = 'whsec_route_secret';

beforeEach(function () {
    config()->set('leadmail.webhook_secret', ROUTE_SECRET);
});

/**
 * @return array{0: string, 1: string} [body, signature]
 */
function signedBody(array $payload, string $secret = ROUTE_SECRET): array
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

    return [$body, 'sha256='.hash_hmac('sha256', $body, $secret)];
}

function postWebhook(string $body, ?string $signature)
{
    $server = ['CONTENT_TYPE' => 'application/json'];

    if ($signature !== null) {
        $server['HTTP_X_LEADMAIL_SIGNATURE'] = $signature;
    }

    return test()->call('POST', '/webhooks/leadmail', [], [], [], $server, $body);
}

it('auto-registers the webhook route', function () {
    expect(Route::has('leadmail.webhook'))->toBeTrue();
});

it('accepts a validly signed webhook and dispatches the event', function () {
    Event::fake([LeadMailWebhookReceived::class]);

    [$body, $signature] = signedBody([
        'event' => 'email.failed',
        'log_id' => 85,
        'error' => ['code' => 'TRANSPORT_ERROR', 'message' => 'Sender address not verified'],
        'to' => ['lead@example.com'],
    ]);

    postWebhook($body, $signature)->assertNoContent();

    Event::assertDispatched(LeadMailWebhookReceived::class, function ($e) {
        return $e->event->logId === 85 && $e->event->isFailure();
    });
});

it('rejects an invalid signature with 403 and dispatches nothing', function () {
    Event::fake([LeadMailWebhookReceived::class]);

    [$body] = signedBody(['event' => 'email.failed', 'log_id' => 1]);

    postWebhook($body, 'sha256=deadbeef')->assertForbidden();

    Event::assertNotDispatched(LeadMailWebhookReceived::class);
});

it('rejects a request with no signature header', function () {
    [$body] = signedBody(['event' => 'email.failed', 'log_id' => 1]);

    postWebhook($body, null)->assertForbidden();
});
