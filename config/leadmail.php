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
];
