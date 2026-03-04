<?php

namespace LeadM\LeadMail;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use LeadM\LeadMail\Rules\LeadMailVerify;

class LeadMailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/leadmail.php', 'leadmail');

        $this->app->singleton(LeadMailClient::class, function () {
            return new LeadMailClient(
                baseUrl: config('leadmail.url'),
                token: config('leadmail.token', ''),
                timeout: config('leadmail.timeout', 30),
                verifySsl: config('leadmail.verify_ssl', true),
                autoTenant: config('leadmail.auto_tenant', true),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/leadmail.php' => config_path('leadmail.php'),
            ], 'leadmail-config');
        }

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
}
