<?php

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LeadM\LeadMail\LeadMailClient;

/**
 * @param  array<int, mixed>  $queue
 */
function webhookClient(array $queue): LeadMailClient
{
    return new LeadMailClient(
        baseUrl: 'https://mail.test',
        token: 'lm_test',
        retryDelayMs: 0,
        handlerStack: HandlerStack::create(new MockHandler($queue)),
    );
}

it('registers a webhook and returns the generated secret', function () {
    $client = webhookClient([
        new Response(200, [], json_encode([
            'success' => true,
            'data' => ['url' => 'https://app.test/hook', 'has_secret' => true, 'secret' => 'whsec_abc'],
        ])),
    ]);

    $result = $client->registerWebhook('https://app.test/hook');

    expect($result['url'])->toBe('https://app.test/hook')
        ->and($result['has_secret'])->toBeTrue()
        ->and($result['secret'])->toBe('whsec_abc');
});

it('reads the current webhook configuration', function () {
    $client = webhookClient([
        new Response(200, [], json_encode([
            'success' => true,
            'data' => ['url' => 'https://app.test/hook', 'has_secret' => true],
        ])),
    ]);

    $result = $client->getWebhook();

    expect($result)->toBe(['url' => 'https://app.test/hook', 'has_secret' => true]);
});

it('deletes the webhook configuration', function () {
    $client = webhookClient([
        new Response(200, [], json_encode([
            'success' => true,
            'data' => ['url' => null, 'has_secret' => false],
        ])),
    ]);

    expect($client->deleteWebhook())->toBe(['url' => null, 'has_secret' => false]);
});

it('does not retry webhook registration on a 5xx', function () {
    $client = webhookClient([
        new Response(503, [], json_encode(['error' => ['code' => 'X', 'message' => 'down']])),
        new Response(200, [], json_encode(['success' => true, 'data' => ['url' => 'x', 'has_secret' => true]])),
    ]);

    expect(fn () => $client->registerWebhook('https://app.test/hook'))
        ->toThrow(\LeadM\LeadMail\Exceptions\LeadMailRequestException::class);
});
