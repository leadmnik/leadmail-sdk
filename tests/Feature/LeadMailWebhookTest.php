<?php

use Illuminate\Http\Request;
use LeadM\LeadMail\Exceptions\InvalidWebhookSignatureException;
use LeadM\LeadMail\Webhooks\LeadMailWebhook;
use LeadM\LeadMail\Webhooks\WebhookEvent;
use LeadM\LeadMail\Webhooks\WebhookSignature;

const WEBHOOK_SECRET = 'whsec_test_secret';

/**
 * Build a request the exact way the leadMail service signs its webhooks:
 * body = json_encode($payload, JSON_UNESCAPED_SLASHES);
 * signature = 'sha256=' . hash_hmac('sha256', $body, $secret).
 *
 * @param  array<string, mixed>  $payload
 */
function signedWebhookRequest(array $payload, string $secret = WEBHOOK_SECRET): Request
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $signature = 'sha256='.hash_hmac('sha256', $body, $secret);

    return Request::create(
        '/webhooks/leadmail',
        'POST',
        [],
        [],
        [],
        ['HTTP_X_LEADMAIL_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
        $body,
    );
}

/**
 * @return array<string, mixed>
 */
function failurePayload(): array
{
    return [
        'event' => 'email.failed',
        'delivery_id' => 'wd_01hxyz',
        'occurred_at' => '2026-03-06T00:26:24+00:00',
        'log_id' => 85,
        'tenant_id' => 'tenant-7',
        'status' => 'failed',
        'error' => ['code' => 'TRANSPORT_ERROR', 'message' => 'Sender address not verified'],
        'from' => 'noreply@leadmagnet.dev',
        'to' => ['lead@example.com'],
        'subject' => 'Welcome',
        'metadata' => ['campaign' => 'spring'],
    ];
}

it('parses a genuinely signed webhook into a typed event', function () {
    $webhook = new LeadMailWebhook(WEBHOOK_SECRET);

    $event = $webhook->parse(signedWebhookRequest(failurePayload()));

    expect($event)->toBeInstanceOf(WebhookEvent::class)
        ->and($event->isFailure())->toBeTrue()
        ->and($event->logId)->toBe(85)
        ->and($event->tenantId)->toBe('tenant-7')
        ->and($event->errorCode)->toBe('TRANSPORT_ERROR')
        ->and($event->errorMessage)->toBe('Sender address not verified')
        ->and($event->from)->toBe('noreply@leadmagnet.dev')
        ->and($event->to)->toBe(['lead@example.com'])
        ->and($event->metadata)->toBe(['campaign' => 'spring']);
});

it('rejects a tampered body', function () {
    $webhook = new LeadMailWebhook(WEBHOOK_SECRET);
    $request = signedWebhookRequest(failurePayload());

    $tampered = Request::create(
        '/webhooks/leadmail',
        'POST',
        [],
        [],
        [],
        ['HTTP_X_LEADMAIL_SIGNATURE' => $request->header(WebhookSignature::HEADER)],
        json_encode(['event' => 'email.failed', 'log_id' => 999]),
    );

    expect($webhook->verify($tampered))->toBeFalse()
        ->and(fn () => $webhook->parse($tampered))
        ->toThrow(InvalidWebhookSignatureException::class);
});

it('rejects a request signed with the wrong secret', function () {
    $webhook = new LeadMailWebhook(WEBHOOK_SECRET);

    $request = signedWebhookRequest(failurePayload(), 'the_wrong_secret');

    expect($webhook->verify($request))->toBeFalse();
});

it('rejects a request with no signature header', function () {
    $webhook = new LeadMailWebhook(WEBHOOK_SECRET);

    $request = Request::create('/webhooks/leadmail', 'POST', [], [], [], [], json_encode(failurePayload()));

    expect($webhook->verify($request))->toBeFalse()
        ->and(fn () => $webhook->parse($request))
        ->toThrow(InvalidWebhookSignatureException::class);
});

it('treats an empty configured secret as never valid', function () {
    $webhook = new LeadMailWebhook('');

    expect($webhook->verify(signedWebhookRequest(failurePayload())))->toBeFalse();
});

it('resolves the webhook helper from the container with the configured secret', function () {
    config()->set('leadmail.webhook_secret', WEBHOOK_SECRET);

    $event = app(LeadMailWebhook::class)->parse(signedWebhookRequest(failurePayload()));

    expect($event->logId)->toBe(85);
});

it('degrades gracefully when optional fields are missing', function () {
    $event = WebhookEvent::fromArray(['event' => 'email.failed']);

    expect($event->isFailure())->toBeTrue()
        ->and($event->logId)->toBeNull()
        ->and($event->to)->toBe([])
        ->and($event->metadata)->toBeNull();
});
