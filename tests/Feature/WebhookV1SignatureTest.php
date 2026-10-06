<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use LeadM\LeadMail\Events\LeadMailWebhookReceived;
use LeadM\LeadMail\Webhooks\LeadMailWebhook;
use LeadM\LeadMail\Webhooks\WebhookSignature;

/*
 * LeadMail since MAIL-20b signs each webhook twice: `X-LeadMail-Signature`
 * as before, and HMAC v1 (`X-LM-Peer`, `X-LM-Timestamp`, `X-LM-Signature`)
 * keyed with the same webhook secret. When v1 is present it must match:
 * the timestamp within 300 seconds, a constant-time compare, and a
 * delivery id already handled is refused. Without it, nothing changes.
 */

const V1_SECRET = 'whsec_test_secret';

/**
 * @param  array<string, string>  $override
 */
function v1Request(string $body, ?int $timestamp = null, string $uri = '/webhooks/leadmail', string $delivery = 'wd_01hxyz', array $override = []): Request
{
    $timestamp ??= time();
    $path = (string) parse_url($uri, PHP_URL_PATH);
    $query = (string) (parse_url($uri, PHP_URL_QUERY) ?? '');

    $headers = [
        'HTTP_X_LEADMAIL_SIGNATURE' => WebhookSignature::compute($body, V1_SECRET),
        'HTTP_X_LEADMAIL_DELIVERY' => $delivery,
        'HTTP_X_LM_PEER' => 'leadmail',
        'HTTP_X_LM_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_LM_SIGNATURE' => WebhookSignature::computeV1($path, $query, $timestamp, $body, V1_SECRET),
        'CONTENT_TYPE' => 'application/json',
        ...$override,
    ];

    return Request::create($uri, 'POST', [], [], [], $headers, $body);
}

function v1Body(): string
{
    return '{"event":"email.failed","delivery_id":"wd_01hxyz","log_id":85}';
}

it('matches the test vector in the ecosystem contract (leadmail.md "Webhook")', function () {
    expect(WebhookSignature::computeV1('/webhooks/leadmail', '', 1790000000, v1Body(), V1_SECRET))
        ->toBe('v1=86d89acaeba09e91a2dfdcd50c4b7d018c06bdc858b44e1c93779b4aef53af94')
        ->and(WebhookSignature::compute(v1Body(), V1_SECRET))
        ->toBe('sha256=d517523a7cb2401af5ffed10cc316c119925be9297a6edf5c0217e9603e7bbea')
        ->and(WebhookSignature::canonicalV1('webhooks/leadmail', 'b=2&a=1', 1790000000, v1Body()))
        ->toBe("v1\nleadmail\nPOST\n/webhooks/leadmail\na=1&b=2\n1790000000\nc5a3d4cf1d1a3c3a255b11642bd1d2ac955b38a878e4340aa614577423c6fd8a");
});

it('accepts a request signed both ways, with its query string', function () {
    $webhook = new LeadMailWebhook(V1_SECRET, cache()->store());

    expect($webhook->verify(v1Request(v1Body())))->toBeTrue()
        ->and($webhook->verify(v1Request(v1Body(), uri: '/webhooks/leadmail?app=northform&b=1')))->toBeTrue();
});

it('refuses a v1 timestamp more than 300 seconds off, either way', function (int $offset, bool $valid) {
    $webhook = new LeadMailWebhook(V1_SECRET, cache()->store());

    expect($webhook->verify(v1Request(v1Body(), time() + $offset)))->toBe($valid);
})->with([
    'just now' => [0, true],
    '299 s old' => [-299, true],
    '301 s old' => [-301, false],
    '301 s ahead' => [301, false],
]);

it('refuses a wrong v1 signature even when the body signature is right', function () {
    $webhook = new LeadMailWebhook(V1_SECRET, cache()->store());

    expect($webhook->verify(v1Request(v1Body(), override: ['HTTP_X_LM_SIGNATURE' => 'v1='.str_repeat('0', 64)])))->toBeFalse()
        ->and($webhook->verify(v1Request(v1Body(), override: ['HTTP_X_LM_TIMESTAMP' => 'soon'])))->toBeFalse()
        // Signed for another path: a request moved elsewhere.
        ->and($webhook->verify(v1Request(v1Body(), override: ['HTTP_X_LM_SIGNATURE' => WebhookSignature::computeV1('/other', '', time(), v1Body(), V1_SECRET)])))->toBeFalse();
});

it('a 2.x request without v1 is checked as before', function () {
    $webhook = new LeadMailWebhook(V1_SECRET, cache()->store());
    $request = Request::create('/webhooks/leadmail', 'POST', [], [], [], ['HTTP_X_LEADMAIL_SIGNATURE' => WebhookSignature::compute(v1Body(), V1_SECRET)], v1Body());

    expect($webhook->verify($request))->toBeTrue();
    $webhook->markHandled($request);
    expect($webhook->verify($request))->toBeTrue();
});

it('the route refuses a delivery it handled before, and takes a retry of one that failed', function () {
    config()->set('leadmail.webhook_secret', V1_SECRET);
    Event::fake([LeadMailWebhookReceived::class]);

    $post = function (string $delivery) {
        $request = v1Request(v1Body(), delivery: $delivery);

        return test()->call('POST', '/webhooks/leadmail', [], [], [], collect($request->server->all())->filter(fn ($v, $k) => str_starts_with($k, 'HTTP_X_') || $k === 'CONTENT_TYPE')->all(), v1Body());
    };

    $post('wd_first')->assertNoContent();
    $post('wd_first')->assertForbidden();
    $post('wd_second')->assertNoContent();

    Event::assertDispatchedTimes(LeadMailWebhookReceived::class, 2);
});

it('a delivery whose handling failed is not remembered, so LeadMail\'s retry passes', function () {
    $webhook = new LeadMailWebhook(V1_SECRET, cache()->store());
    $request = v1Request(v1Body(), delivery: 'wd_retry');

    $webhook->parse($request);
    // The app failed before markHandled(): LeadMail tries again with the same delivery id.
    expect($webhook->verify(v1Request(v1Body(), delivery: 'wd_retry')))->toBeTrue();
});

it('with webhook_require_v1 on, a request without v1 is refused', function () {
    $request = Request::create('/webhooks/leadmail', 'POST', [], [], [], ['HTTP_X_LEADMAIL_SIGNATURE' => WebhookSignature::compute(v1Body(), V1_SECRET)], v1Body());

    expect((new LeadMailWebhook(V1_SECRET, cache()->store(), requireV1: true))->verify($request))->toBeFalse()
        ->and((new LeadMailWebhook(V1_SECRET, cache()->store(), requireV1: true))->verify(v1Request(v1Body())))->toBeTrue();
});
