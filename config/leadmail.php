<?php

return [
    /*
    |--------------------------------------------------------------------------
    | LeadMail Service URL
    |--------------------------------------------------------------------------
    |
    | The base URL of your leadMail service instance.
    |
    */
    'url' => env('LEADMAIL_URL', 'https://mail.leadmagnet.dev'),

    /*
    |--------------------------------------------------------------------------
    | API Token
    |--------------------------------------------------------------------------
    |
    | The bearer token for authenticating with the leadMail API.
    | Generate this in the leadMail admin dashboard.
    |
    */
    'token' => env('LEADMAIL_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum number of seconds to wait for API responses.
    |
    */
    'timeout' => env('LEADMAIL_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | SSL Verification
    |--------------------------------------------------------------------------
    |
    | Whether to verify SSL certificates. Disable only for local development.
    |
    */
    'verify_ssl' => env('LEADMAIL_VERIFY_SSL', true),

    /*
    |--------------------------------------------------------------------------
    | Auto Tenant Detection
    |--------------------------------------------------------------------------
    |
    | When enabled and stancl/tenancy is installed, automatically sends the
    | current tenant's key as the X-Tenant-Id header on every request.
    |
    */
    'auto_tenant' => env('LEADMAIL_AUTO_TENANT', true),

    /*
    |--------------------------------------------------------------------------
    | Retries
    |--------------------------------------------------------------------------
    |
    | How many times to retry a request that fails transiently (connection
    | errors and 429/5xx responses) before giving up. The delay between
    | attempts grows exponentially from the base delay (in milliseconds).
    |
    */
    'retries' => env('LEADMAIL_RETRIES', 2),

    'retry_delay' => env('LEADMAIL_RETRY_DELAY_MS', 200),

    /*
    |--------------------------------------------------------------------------
    | Webhook Secret
    |--------------------------------------------------------------------------
    |
    | The signing secret for verifying incoming failure webhooks. This must
    | match the webhook secret shown for this client app in the leadMail admin
    | dashboard. Set automatically by `php artisan leadmail:install`.
    |
    */
    'webhook_secret' => env('LEADMAIL_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Webhook Route
    |--------------------------------------------------------------------------
    |
    | The path the SDK automatically registers to receive failure webhooks.
    | The registered endpoint verifies the signature, logs failures, and fires
    | a LeadMailWebhookReceived event you can listen for. Set to null to
    | disable auto-registration and handle the route yourself.
    |
    */
    'webhook_route' => env('LEADMAIL_WEBHOOK_ROUTE', '/webhooks/leadmail'),

    /*
    |--------------------------------------------------------------------------
    | Webhook Route Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware applied to the auto-registered webhook route. The endpoint is
    | already authenticated by HMAC signature, so it deliberately runs outside
    | the "web" group (no CSRF, no session).
    |
    */
    'webhook_middleware' => ['throttle:60,1'],
];
