<?php

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LeadM\LeadMail\LeadMailClient;

/**
 * Bind a client backed by canned HTTP responses into the container so the
 * command resolves it instead of making real requests.
 *
 * @param  array<int, mixed>  $queue
 */
function bindMockedClient(array $queue): void
{
    app()->instance(LeadMailClient::class, new LeadMailClient(
        baseUrl: 'https://mail.test',
        token: 'lm_test',
        retryDelayMs: 0,
        handlerStack: HandlerStack::create(new MockHandler($queue)),
    ));
}

/**
 * Point the application at a throwaway .env file and return its path.
 */
function useTempEnv(string $contents = "APP_NAME=Test\n"): string
{
    $dir = sys_get_temp_dir().'/leadmail-test-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/.env', $contents);
    app()->useEnvironmentPath($dir);

    return $dir.'/.env';
}

function registrationResponse(?string $secret): Response
{
    $data = ['url' => 'https://app.test/webhooks/leadmail', 'has_secret' => true];

    if ($secret !== null) {
        $data['secret'] = $secret;
    }

    return new Response(200, [], json_encode(['success' => true, 'data' => $data]));
}

it('derives the webhook url from app.url and the configured route without prompting', function () {
    config()->set('leadmail.token', 'lm_test');
    config()->set('app.url', 'https://helios.test');
    config()->set('leadmail.webhook_route', '/webhooks/leadmail');
    useTempEnv();

    $mock = new MockHandler([registrationResponse('whsec_x')]);
    app()->instance(LeadMailClient::class, new LeadMailClient(
        baseUrl: 'https://mail.test',
        token: 'lm_test',
        retryDelayMs: 0,
        handlerStack: HandlerStack::create($mock),
    ));

    $this->artisan('leadmail:install', ['--no-publish' => true])->assertSuccessful();

    $sent = json_decode((string) $mock->getLastRequest()->getBody(), true);
    expect($sent['url'])->toBe('https://helios.test/webhooks/leadmail');
});

it('registers the webhook and writes the secret to .env', function () {
    config()->set('leadmail.token', 'lm_test');
    $envPath = useTempEnv();
    bindMockedClient([registrationResponse('whsec_written')]);

    $this->artisan('leadmail:install', [
        '--url' => 'https://app.test/webhooks/leadmail',
        '--no-publish' => true,
    ])->assertSuccessful();

    expect(file_get_contents($envPath))->toContain('LEADMAIL_WEBHOOK_SECRET=whsec_written');
});

it('replaces an existing secret line instead of duplicating it', function () {
    config()->set('leadmail.token', 'lm_test');
    $envPath = useTempEnv("APP_NAME=Test\nLEADMAIL_WEBHOOK_SECRET=whsec_old\n");
    bindMockedClient([registrationResponse('whsec_new')]);

    $this->artisan('leadmail:install', [
        '--url' => 'https://app.test/webhooks/leadmail',
        '--no-publish' => true,
    ])->assertSuccessful();

    $contents = file_get_contents($envPath);
    expect($contents)->toContain('whsec_new')
        ->and($contents)->not->toContain('whsec_old')
        ->and(substr_count($contents, 'LEADMAIL_WEBHOOK_SECRET='))->toBe(1);
});

it('does not touch .env when the url is updated without a new secret', function () {
    config()->set('leadmail.token', 'lm_test');
    $envPath = useTempEnv();
    bindMockedClient([registrationResponse(null)]);

    $this->artisan('leadmail:install', [
        '--url' => 'https://app.test/webhooks/leadmail',
        '--no-publish' => true,
    ])->assertSuccessful();

    expect(file_get_contents($envPath))->not->toContain('LEADMAIL_WEBHOOK_SECRET');
});

it('fails when the api token is not configured', function () {
    config()->set('leadmail.token', null);
    useTempEnv();

    $this->artisan('leadmail:install', [
        '--url' => 'https://app.test/webhooks/leadmail',
        '--no-publish' => true,
    ])->assertFailed();
});
