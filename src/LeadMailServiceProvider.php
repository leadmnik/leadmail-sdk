<?php

namespace LeadM\LeadMail;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use LeadM\LeadMail\Console\InstallCommand;
use LeadM\LeadMail\Http\Controllers\LeadMailWebhookController;
use LeadM\LeadMail\Rules\LeadMailVerify;
use LeadM\LeadMail\Webhooks\LeadMailWebhook;

class LeadMailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/leadmail.php', 'leadmail');

        $this->app->booted(function () {
            config(['mail.mailers.leadmail' => ['transport' => 'leadmail']]);
        });

        $this->app->singleton(LeadMailClient::class, function () {
            return new LeadMailClient(
                baseUrl: (string) config('leadmail.url'),
                token: (string) config('leadmail.token'),
                timeout: (int) config('leadmail.timeout', 30),
                verifySsl: config('leadmail.verify_ssl', true),
                autoTenant: config('leadmail.auto_tenant', true),
                retries: (int) config('leadmail.retries', 2),
                retryDelayMs: (int) config('leadmail.retry_delay', 200),
            );
        });

        $this->app->singleton(LeadMailWebhook::class, function () {
            return new LeadMailWebhook(
                (string) config('leadmail.webhook_secret', ''),
                $this->app->make('cache.store'),
                (bool) config('leadmail.webhook_require_v1', false),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/leadmail.php' => config_path('leadmail.php'),
            ], 'leadmail-config');

            $this->commands([InstallCommand::class]);
        }

        $this->registerWebhookRoute();

        Mail::extend('leadmail', function () {
            return new LeadMailTransport(
                $this->app->make(LeadMailClient::class),
            );
        });

        Validator::extend('leadmail_verify', function (string $attribute, mixed $value) {
            $rule = $this->app->make(LeadMailVerify::class);
            $passed = true;

            $rule->validate($attribute, $value, function () use (&$passed) {
                $passed = false;
            });

            return $passed;
        }, 'The :attribute email address could not be verified.');
    }

    /**
     * Auto-register the webhook receiver route unless disabled in config.
     */
    protected function registerWebhookRoute(): void
    {
        $path = config('leadmail.webhook_route');

        if (blank($path)) {
            return;
        }

        Route::post($path, LeadMailWebhookController::class)
            ->middleware((array) config('leadmail.webhook_middleware', []))
            ->name('leadmail.webhook');
    }
}
