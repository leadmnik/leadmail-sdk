<?php

namespace LeadM\LeadMail\Console;

use Illuminate\Console\Command;
use LeadM\LeadMail\Exceptions\LeadMailException;
use LeadM\LeadMail\LeadMailClient;

class InstallCommand extends Command
{
    protected $signature = 'leadmail:install
        {--url= : Override the webhook URL (defaults to APP_URL + the configured webhook route)}
        {--rotate : Force generation of a new signing secret}
        {--no-publish : Skip publishing the config file}';

    protected $description = 'Publish config and connect this app to the leadMail webhook service';

    public function handle(LeadMailClient $client): int
    {
        if (! $this->option('no-publish')) {
            $this->call('vendor:publish', ['--tag' => 'leadmail-config']);
        }

        if (blank(config('leadmail.token'))) {
            $this->components->error('LEADMAIL_TOKEN is not set. Add it to your .env, then re-run this command.');

            return self::FAILURE;
        }

        $url = $this->option('url') ?: $this->defaultWebhookUrl();

        try {
            $result = $client->registerWebhook($url, regenerateSecret: (bool) $this->option('rotate'));
        } catch (LeadMailException $e) {
            $this->components->error('Could not register the webhook: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! empty($result['secret'])) {
            $written = $this->writeEnv('LEADMAIL_WEBHOOK_SECRET', $result['secret']);

            $written
                ? $this->components->info('Webhook registered. Signing secret written to your .env file.')
                : $this->components->warn('Webhook registered. Add this to your .env: LEADMAIL_WEBHOOK_SECRET='.$result['secret']);
        } else {
            $this->components->info('Webhook URL updated. The existing signing secret was kept (use --rotate to replace it).');
        }

        $this->components->bulletList(array_filter([
            'Webhook URL: '.($result['url'] ?? $url),
            'This URL is served automatically by the SDK — failures are verified and logged out of the box.',
            'Add a LeadMailWebhookReceived listener if you want custom handling.',
        ]));

        return self::SUCCESS;
    }

    /**
     * Derive the webhook URL from the app URL and the configured webhook route.
     */
    protected function defaultWebhookUrl(): string
    {
        $base = rtrim((string) config('app.url'), '/');
        $path = '/'.ltrim((string) (config('leadmail.webhook_route') ?: '/webhooks/leadmail'), '/');

        return $base.$path;
    }

    /**
     * Write or replace a key in the application's .env file.
     */
    protected function writeEnv(string $key, string $value): bool
    {
        $path = $this->laravel->environmentFilePath();

        if (! is_file($path) || ! is_writable($path)) {
            return false;
        }

        $contents = (string) file_get_contents($path);
        $line = $key.'='.$this->escapeEnvValue($value);
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        $contents = preg_match($pattern, $contents)
            ? (string) preg_replace($pattern, $line, $contents)
            : rtrim($contents, "\n")."\n".$line."\n";

        return file_put_contents($path, $contents) !== false;
    }

    protected function escapeEnvValue(string $value): string
    {
        return preg_match('/\s|[#"\']/', $value) ? '"'.addcslashes($value, '"\\').'"' : $value;
    }
}
