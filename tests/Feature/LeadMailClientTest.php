<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LeadM\LeadMail\Exceptions\LeadMailConnectionException;
use LeadM\LeadMail\Exceptions\LeadMailException;
use LeadM\LeadMail\Exceptions\LeadMailRequestException;
use LeadM\LeadMail\LeadMailClient;

/**
 * @param  array<int, mixed>  $queue
 */
function clientWithResponses(array $queue): LeadMailClient
{
    $mock = new MockHandler($queue);

    return new LeadMailClient(
        baseUrl: 'https://mail.test',
        token: 'lm_test',
        retries: 2,
        retryDelayMs: 0,
        handlerStack: HandlerStack::create($mock),
    );
}

it('returns the decoded body on success', function () {
    $client = clientWithResponses([
        new Response(202, [], json_encode([
            'success' => true,
            'data' => ['log_id' => 42, 'status' => 'queued'],
        ])),
    ]);

    $result = $client->sendEmail(['to' => []]);

    expect($result['data']['log_id'])->toBe(42)
        ->and($result['success'])->toBeTrue();
});

it('throws a request exception with structured fields on a transport error', function () {
    $client = clientWithResponses([
        new Response(502, [], json_encode([
            'success' => false,
            'error' => ['code' => 'TRANSPORT_ERROR', 'message' => 'Sender address not verified'],
            'data' => ['log_id' => 85, 'status' => 'failed'],
        ])),
    ]);

    try {
        $client->sendEmail(['to' => []]);
        $this->fail('Expected LeadMailRequestException');
    } catch (LeadMailRequestException $e) {
        expect($e->statusCode())->toBe(502)
            ->and($e->errorCode())->toBe('TRANSPORT_ERROR')
            ->and($e->logId())->toBe(85)
            ->and($e->getMessage())->toBe('Sender address not verified');
    }
});

it('never retries a send, even on a retryable 5xx', function () {
    $client = clientWithResponses([
        new Response(503, [], json_encode(['error' => ['code' => 'X', 'message' => 'down']])),
        // If sends were retried this 200 would be consumed and the call would succeed.
        new Response(200, [], json_encode(['success' => true, 'data' => ['log_id' => 1, 'status' => 'sent']])),
    ]);

    expect(fn () => $client->sendEmail(['to' => []]))->toThrow(LeadMailRequestException::class);
});

it('parses laravel validation errors from a 422', function () {
    $client = clientWithResponses([
        new Response(422, [], json_encode([
            'message' => 'The given data was invalid.',
            'errors' => ['from.email' => ['The from.email field is required.']],
        ])),
    ]);

    try {
        $client->sendEmail(['to' => []]);
        $this->fail('Expected LeadMailRequestException');
    } catch (LeadMailRequestException $e) {
        expect($e->isValidationError())->toBeTrue()
            ->and($e->validationErrors())->toHaveKey('from.email');
    }
});

it('flags authentication errors from a string error envelope', function () {
    $client = clientWithResponses([
        new Response(401, [], json_encode(['error' => 'Invalid API token.'])),
    ]);

    try {
        $client->getDomains();
        $this->fail('Expected LeadMailRequestException');
    } catch (LeadMailRequestException $e) {
        expect($e->isAuthenticationError())->toBeTrue()
            ->and($e->getMessage())->toBe('Invalid API token.');
    }
});

it('retries transient 5xx responses then succeeds', function () {
    $client = clientWithResponses([
        new Response(503, [], 'service unavailable'),
        new Response(200, [], json_encode([
            'success' => true,
            'data' => ['domains' => ['leadmagnet.dev']],
        ])),
    ]);

    expect($client->getDomains())->toBe(['leadmagnet.dev']);
});

it('gives up after exhausting retries on persistent 5xx', function () {
    $client = clientWithResponses([
        new Response(500, [], json_encode(['error' => ['code' => 'X', 'message' => 'boom']])),
        new Response(500, [], json_encode(['error' => ['code' => 'X', 'message' => 'boom']])),
        new Response(500, [], json_encode(['error' => ['code' => 'X', 'message' => 'boom']])),
    ]);

    expect(fn () => $client->getDomains())->toThrow(LeadMailRequestException::class);
});

it('does not retry 4xx responses', function () {
    $client = clientWithResponses([
        new Response(422, [], json_encode(['message' => 'nope', 'errors' => ['a' => ['b']]])),
        new Response(200, [], json_encode(['success' => true, 'data' => ['domains' => []]])),
    ]);

    // If it retried, it would consume the 200 and succeed. It must throw instead.
    expect(fn () => $client->getDomains())->toThrow(LeadMailRequestException::class);
});

it('wraps connection failures in a connection exception', function () {
    $client = clientWithResponses([
        new ConnectException('Connection refused', new Request('GET', 'domains')),
        new ConnectException('Connection refused', new Request('GET', 'domains')),
        new ConnectException('Connection refused', new Request('GET', 'domains')),
    ]);

    expect(fn () => $client->getDomains())->toThrow(LeadMailConnectionException::class);
});

it('throws on a malformed json body', function () {
    $client = clientWithResponses([
        new Response(200, [], '<html>not json</html>'),
    ]);

    expect(fn () => $client->getDomains())->toThrow(LeadMailException::class, 'malformed');
});

it('fails open when verification cannot be reached', function () {
    $client = clientWithResponses([
        new ConnectException('Connection refused', new Request('POST', 'emails/verify')),
        new ConnectException('Connection refused', new Request('POST', 'emails/verify')),
        new ConnectException('Connection refused', new Request('POST', 'emails/verify')),
    ]);

    $result = $client->verifyEmail('user@example.com');

    expect($result['success'])->toBeTrue()
        ->and($result['data']['valid'])->toBeTrue()
        ->and($result['data']['status'])->toBe('unknown');
});
